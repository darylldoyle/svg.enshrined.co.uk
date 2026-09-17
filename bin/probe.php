<?php
/**
 * Loads one installed library version, runs a trivial SVG through it and
 * reports what that version supports.
 *
 * Runs as its own process so a version that cannot run on the host PHP takes
 * down only this probe. Prints one JSON object between sentinels, because
 * decade-old code tends to emit deprecation notices on the way past.
 */

declare(strict_types=1);

const PROBE_BEGIN = '<<<PROBE';
const PROBE_END   = 'PROBE>>>';

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

require dirname(__DIR__) . '/src/bootstrap.php';

$version = $argv[1] ?? '';
$notices = [];

set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0) use (&$notices): bool {
    $label = [
        E_DEPRECATED      => 'deprecated',
        E_USER_DEPRECATED => 'deprecated',
        E_WARNING         => 'warning',
        E_USER_WARNING    => 'warning',
        E_NOTICE          => 'notice',
        E_USER_NOTICE     => 'notice',
        E_STRICT          => 'strict',
    ][$level] ?? 'error';

    $key = $label . ':' . $message;

    if (!isset($notices[$key]) && count($notices) < 25) {
        $notices[$key] = [
            'level'   => $label,
            'message' => $message,
            'source'  => $file === '' ? null : basename($file) . ':' . $line,
        ];
    }

    return true;
});

$report = static function (array $data) use (&$notices): void {
    $data['notices'] = array_values($notices);

    echo PROBE_BEGIN, json_encode($data), PROBE_END, "\n";
    exit(0);
};

// Fatals bypass catch blocks entirely, so report those from the shutdown handler.
register_shutdown_function(static function () use ($version, &$notices): void {
    $error = error_get_last();

    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
        return;
    }

    echo PROBE_BEGIN, json_encode([
        'version'    => $version,
        'compatible' => false,
        'error'      => sprintf('%s (%s:%d)', $error['message'], basename($error['file']), $error['line']),
        'features'   => [],
        'notices'    => array_values($notices),
    ]), PROBE_END, "\n";
});

if (!SvgTest\VersionRegistry::isWellFormed($version)) {
    $report(['version' => $version, 'compatible' => false, 'error' => 'Malformed version string.', 'features' => []]);
}

$autoload = SvgTest\VersionRegistry::autoloadPath($version);

if (!is_file($autoload)) {
    $report(['version' => $version, 'compatible' => false, 'error' => 'Not installed.', 'features' => []]);
}

require $autoload;

if (!class_exists('enshrined\svgSanitize\Sanitizer')) {
    $report(['version' => $version, 'compatible' => false, 'error' => 'Sanitizer class missing.', 'features' => []]);
}

$sanitizer = new enshrined\svgSanitize\Sanitizer();

$features = [];
foreach (['removeRemoteReferences', 'minify', 'removeXMLTag', 'setAllowHugeFiles', 'useThreshold', 'setUseNestingLimit', 'getXmlIssues'] as $method) {
    $features[$method] = method_exists($sanitizer, $method);
}

$probe = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="1" height="1"/></svg>';

try {
    $clean = $sanitizer->sanitize($probe);
} catch (Throwable $e) {
    $report([
        'version'    => $version,
        'compatible' => false,
        'error'      => get_class($e) . ': ' . $e->getMessage(),
        'features'   => $features,
    ]);
}

if (!is_string($clean)) {
    $report([
        'version'    => $version,
        'compatible' => false,
        'error'      => 'sanitize() did not return a string for a known-good document.',
        'features'   => $features,
    ]);
}

$report([
    'version'      => $version,
    'compatible'   => true,
    'error'        => null,
    'features'     => $features,
    'stripsScript' => stripos($clean, '<script') === false,
]);
