<?php
/**
 * JSON endpoint for the playground.
 *
 * Handles exactly one library version per request. Every version ships the same
 * `enshrined\svgSanitize\Sanitizer` class, so a single process can only hold one
 * of them — the browser fans out across versions instead, which also means one
 * version blowing up cannot take the others with it.
 */

declare(strict_types=1);

// Releases going back to 2015 trip deprecation notices on a modern PHP. Those
// must never reach the response body, so keep them out of the output and hand
// them back as structured data instead.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ob_start();

$phpNotices = [];

set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0) use (&$phpNotices): bool {
    $label = [
        E_DEPRECATED      => 'deprecated',
        E_USER_DEPRECATED => 'deprecated',
        E_WARNING         => 'warning',
        E_USER_WARNING    => 'warning',
        E_NOTICE          => 'notice',
        E_USER_NOTICE     => 'notice',
    ][$level] ?? 'error';

    $key = $label . '|' . $message;

    if (!isset($phpNotices[$key]) && count($phpNotices) < 20) {
        $phpNotices[$key] = [
            'level'   => $label,
            'message' => $message,
            'source'  => $file === '' ? null : basename($file) . ':' . $line,
        ];
    }

    return true;
});

require dirname(__DIR__) . '/src/bootstrap.php';

use SvgTest\Diff;
use SvgTest\SanitizeService;
use SvgTest\VersionRegistry;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/** @param array<string,mixed> $payload */
$respond = static function (array $payload, int $status = 200) use (&$phpNotices): never {
    if ($phpNotices !== []) {
        $payload['phpNotices'] = array_values($phpNotices);
    }

    // Drop anything a library printed on its own account; only JSON goes out.
    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

$fail = static fn (string $message, int $status = 400): never => $respond(['ok' => false, 'error' => $message], $status);

// A version compiled for PHP 5 can still fatal on modern PHP. Answer with JSON
// rather than an empty 500 so the UI can show what happened.
register_shutdown_function(static function (): void {
    $error = error_get_last();

    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode([
        'ok'    => false,
        'fatal' => true,
        'error' => sprintf('%s (%s:%d)', $error['message'], basename($error['file']), $error['line']),
    ], JSON_UNESCAPED_SLASHES);
});

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $fail('Use POST.', 405);
}

$raw = file_get_contents('php://input');

if ($raw === false || $raw === '') {
    $fail('Empty request body.');
}

if (strlen($raw) > SVGTEST_MAX_INPUT_BYTES + 8192) {
    $fail('That payload is larger than this playground accepts.', 413);
}

$request = json_decode($raw, true);

if (!is_array($request)) {
    $fail('Request body must be a JSON object.');
}

$action = is_string($request['action'] ?? null) ? $request['action'] : 'sanitize';

switch ($action) {
    case 'sanitize':
        $respond(handleSanitize($request, $fail));
        // no break — handleSanitize always responds.

    case 'diff':
        $respond(handleDiff($request, $fail));

    default:
        $fail('Unknown action.');
}

/**
 * @param array<string,mixed> $request
 * @return array<string,mixed>
 */
function handleSanitize(array $request, callable $fail): array
{
    $version = is_string($request['version'] ?? null) ? $request['version'] : '';
    $svg     = is_string($request['svg'] ?? null) ? $request['svg'] : '';

    if ($svg === '') {
        $fail('Give me some SVG to work with.');
    }

    if (strlen($svg) > SVGTEST_MAX_INPUT_BYTES) {
        $fail(sprintf('SVG is larger than the %s limit.', formatBytes(SVGTEST_MAX_INPUT_BYTES)), 413);
    }

    if (!VersionRegistry::isWellFormed($version)) {
        $fail('That is not a version number I recognise.');
    }

    $registry = new VersionRegistry();

    if (!$registry->isUsable($version)) {
        $fail('Version ' . $version . ' is not available on this server.', 404);
    }

    $options = SanitizeService::normaliseOptions(
        is_array($request['options'] ?? null) ? $request['options'] : []
    );

    $result = (new SanitizeService())->run($version, $svg, $options);

    $result['ok']        = $result['ok'];
    $result['cleanHash'] = $result['clean'] === null ? null : hash('sha256', $result['clean']);

    // The diff is the expensive part, so let the caller skip it when it is only
    // collecting output hashes for the cross-version comparison.
    if (($request['diff'] ?? true) && $result['clean'] !== null) {
        $result['diff'] = Diff::compare($svg, $result['clean'], ignoreWhitespace($request));
    }

    return $result;
}

/**
 * @param array<string,mixed> $request
 * @return array<string,mixed>
 */
function handleDiff(array $request, callable $fail): array
{
    $before = is_string($request['before'] ?? null) ? $request['before'] : null;
    $after  = is_string($request['after'] ?? null) ? $request['after'] : null;

    if ($before === null || $after === null) {
        $fail('A diff needs both `before` and `after`.');
    }

    if (strlen($before) > SVGTEST_MAX_INPUT_BYTES || strlen($after) > SVGTEST_MAX_INPUT_BYTES) {
        $fail('Those documents are too large to diff here.', 413);
    }

    return ['ok' => true, 'diff' => Diff::compare($before, $after, ignoreWhitespace($request))];
}

/** @param array<string,mixed> $request */
function ignoreWhitespace(array $request): bool
{
    return filter_var($request['ignoreWhitespace'] ?? true, FILTER_VALIDATE_BOOLEAN);
}

function formatBytes(int $bytes): string
{
    return $bytes >= 1048576
        ? round($bytes / 1048576, 1) . ' MB'
        : round($bytes / 1024) . ' KB';
}
