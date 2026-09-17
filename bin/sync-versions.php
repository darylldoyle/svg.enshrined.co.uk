<?php
/**
 * Keeps the playground's library versions in step with Packagist.
 *
 * The work splits in two so that nothing is ever installed on the web server
 * that did not arrive through git:
 *
 *   --lock     Ask Packagist what has been released and, for anything new,
 *              write versions/<version>/composer.{json,lock} plus an entry in
 *              versions/index.json. Downloads nothing. This runs in CI; the
 *              resulting files are committed, reviewed and pushed.
 *
 *   --install  Read what is committed, run `composer install` for any version
 *              whose vendor/ is missing, probe every version for compatibility
 *              and write storage/versions.json. This runs on deploy.
 *
 * Running with neither flag does both, which is the self-updating mode for
 * anyone who would rather drive this from cron than from CI.
 *
 * Other options:
 *   --only=1.0.0,0.22.0   Restrict to these versions
 *   --limit=5             Lock at most this many new versions this run
 *   --reinstall           Delete and reinstall matched versions
 *   --prune               Remove local versions that are no longer released
 *   --dry-run             Report what would happen, change nothing
 *   --quiet               Only print warnings and errors
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use SvgTest\VersionRegistry;

const PROBE_BEGIN = '<<<PROBE';
const PROBE_END   = 'PROBE>>>';

const INDEX_PATH    = SVGTEST_VERSIONS_DIR . '/index.json';
const MANIFEST_PATH = SVGTEST_STORAGE_DIR . '/versions.json';

$options = parseArguments($argv);
$quiet   = isset($options['quiet']);
$dryRun  = isset($options['dry-run']);

$doLock    = isset($options['lock']);
$doInstall = isset($options['install']);

if (!$doLock && !$doInstall) {
    $doLock = $doInstall = true;
}

$log = static function (string $message, bool $isError = false) use ($quiet): void {
    if (!$quiet || $isError) {
        fwrite($isError ? STDERR : STDOUT, $message . "\n");
    }
};

foreach ([SVGTEST_STORAGE_DIR, SVGTEST_VERSIONS_DIR] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        fwrite(STDERR, "Cannot create $directory\n");
        exit(1);
    }
}

$composer = locateComposer();

if ($composer === null) {
    fwrite(STDERR, "Composer not found. Set COMPOSER_BIN or put composer on PATH.\n");
    exit(1);
}

$index = readIndex();

// ---------------------------------------------------------------------------
// Lock: discover releases and write the committable files for new ones.
// ---------------------------------------------------------------------------

if ($doLock) {
    $log('Fetching release list from Packagist...');

    try {
        $released = fetchReleases();
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'Could not reach Packagist: ' . $e->getMessage() . "\n");
        exit(1);
    }

    if ($released === []) {
        fwrite(STDERR, "Packagist returned no stable releases.\n");
        exit(1);
    }

    $log(sprintf('Packagist lists %d stable releases (newest: %s).', count($released), array_key_first($released)));

    $wanted = filterRequested(array_keys($released), $options);
    $limit  = isset($options['limit']) ? max(0, (int) $options['limit']) : null;
    $locked = 0;

    foreach ($wanted as $version) {
        $directory = SVGTEST_VERSIONS_DIR . '/' . $version;
        $hasLock   = is_file($directory . '/composer.lock');

        if (isset($options['reinstall']) && !$dryRun) {
            removeDirectory($directory);
            $hasLock = false;
        }

        if ($hasLock) {
            $index[$version] = ['version' => $version, 'released' => $released[$version]] + ($index[$version] ?? []);
            continue;
        }

        if ($limit !== null && $locked >= $limit) {
            $log(sprintf('  %-8s skipped (limit reached)', $version));
            continue;
        }

        if ($dryRun) {
            $log(sprintf('  %-8s would be locked', $version));
            $locked++;
            continue;
        }

        $log(sprintf('  %-8s locking...', $version));
        $result = writeLock($composer, $version, $directory);

        if (!$result['ok']) {
            $log(sprintf('  %-8s LOCK FAILED: %s', $version, lastLine($result['output'])), true);
            removeDirectory($directory);
            continue;
        }

        $index[$version] = ['version' => $version, 'released' => $released[$version]];
        $locked++;
    }

    if (isset($options['prune'])) {
        foreach (localVersions() as $version) {
            if (isset($released[$version])) {
                continue;
            }

            $log(sprintf('  %-8s pruning (no longer released)', $version));

            if (!$dryRun) {
                removeDirectory(SVGTEST_VERSIONS_DIR . '/' . $version);
                unset($index[$version]);
            }
        }
    }

    if (!$dryRun) {
        writeIndex($index);
    }

    $log(sprintf('Lock step complete: %d version(s) newly locked.', $locked));
}

// ---------------------------------------------------------------------------
// Install: build vendor/ from what is committed, then probe.
// ---------------------------------------------------------------------------

if (!$doInstall) {
    exit(0);
}

$versions = filterRequested(localVersions(), $options);

if ($versions === []) {
    fwrite(STDERR, "No versions are checked in under versions/. Run with --lock first.\n");
    exit(1);
}

$entries   = [];
$installed = 0;

foreach ($versions as $version) {
    $directory = SVGTEST_VERSIONS_DIR . '/' . $version;
    $released  = $index[$version]['released'] ?? null;

    if (!is_file(VersionRegistry::autoloadPath($version))) {
        if ($dryRun) {
            $log(sprintf('  %-8s would be installed', $version));
            continue;
        }

        $log(sprintf('  %-8s installing...', $version));
        $result = installVendor($composer, $directory);

        if (!$result['ok']) {
            $log(sprintf('  %-8s INSTALL FAILED: %s', $version, lastLine($result['output'])), true);

            $entries[] = [
                'version'    => $version,
                'released'   => $released,
                'installed'  => false,
                'compatible' => false,
                'error'      => 'Install failed: ' . lastLine($result['output']),
                'features'   => [],
                'notices'    => [],
            ];
            continue;
        }

        $installed++;
    }

    if ($dryRun) {
        continue;
    }

    $probe = probeVersion($version);

    if (!$probe['compatible']) {
        $log(sprintf('  %-8s installed but unusable: %s', $version, $probe['error']), true);
    }

    $entries[] = [
        'version'    => $version,
        'released'   => $released,
        'installed'  => true,
        'compatible' => $probe['compatible'],
        'error'      => $probe['error'],
        'features'   => $probe['features'],
        'notices'    => $probe['notices'],
    ];
}

if ($dryRun) {
    $log('Dry run complete.');
    exit(0);
}

usort($entries, static fn (array $a, array $b): int => version_compare((string) $b['version'], (string) $a['version']));

$usable = array_values(array_filter($entries, static fn (array $e): bool => $e['installed'] && $e['compatible']));

$manifest = [
    'generated_at' => gmdate('c'),
    'package'      => SVGTEST_PACKAGE,
    'php'          => PHP_VERSION,
    'latest'       => $usable === [] ? null : $usable[0]['version'],
    'counts'       => [
        'known'     => count($entries),
        'installed' => count(array_filter($entries, static fn (array $e): bool => (bool) $e['installed'])),
        'usable'    => count($usable),
    ],
    'versions'     => $entries,
];

if (file_put_contents(MANIFEST_PATH, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) {
    fwrite(STDERR, 'Could not write ' . MANIFEST_PATH . "\n");
    exit(1);
}

$log(sprintf(
    'Install step complete: %d newly installed, %d usable of %d known, newest usable is %s.',
    $installed,
    $manifest['counts']['usable'],
    $manifest['counts']['known'],
    $manifest['latest'] ?? 'none'
));

exit(0);

// ---------------------------------------------------------------------------

/** @return array<string,string> version => release date, newest first */
function fetchReleases(): array
{
    $response = httpGet('https://repo.packagist.org/p2/' . SVGTEST_PACKAGE . '.json');
    $decoded  = json_decode($response, true);

    if (!is_array($decoded) || !isset($decoded['packages'][SVGTEST_PACKAGE])) {
        throw new RuntimeException('Unexpected response shape from Packagist.');
    }

    $releases = [];
    $expanded = [];

    // The p2 endpoint is "minified": each entry carries only the fields that
    // differ from the one before it, so expand as we walk the list.
    foreach ($decoded['packages'][SVGTEST_PACKAGE] as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $expanded = array_merge($expanded, $entry);

        foreach ($expanded as $key => $value) {
            if ($value === '__unset') {
                unset($expanded[$key]);
            }
        }

        $version = (string) ($expanded['version'] ?? '');

        if ($version === '' || !VersionRegistry::isWellFormed($version)) {
            continue;
        }

        if (preg_match('/-(alpha|beta|rc|dev)/i', $version)) {
            continue;
        }

        $releases[$version] = substr((string) ($expanded['time'] ?? ''), 0, 10);
    }

    uksort($releases, static fn (string $a, string $b): int => version_compare($b, $a));

    return $releases;
}

function httpGet(string $url): string
{
    $userAgent = 'svg-sanitizer-playground (+https://svg.enshrined.co.uk)';

    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => $userAgent,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);

        $body   = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            throw new RuntimeException($error !== '' ? $error : 'request failed');
        }

        if ($status !== 200) {
            throw new RuntimeException("HTTP $status");
        }

        return (string) $body;
    }

    $context = stream_context_create(['http' => [
        'timeout' => 30,
        'header'  => "Accept: application/json\r\nUser-Agent: $userAgent\r\n",
    ]]);

    $body = @file_get_contents($url, false, $context);

    if ($body === false) {
        throw new RuntimeException('request failed');
    }

    return $body;
}

/**
 * Write composer.json and resolve composer.lock without downloading anything.
 *
 * @return array{ok:bool,output:string}
 */
function writeLock(string $composer, string $version, string $directory): array
{
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return ['ok' => false, 'output' => 'could not create ' . $directory];
    }

    file_put_contents(
        $directory . '/composer.json',
        json_encode([
            'description'       => 'Pinned install of ' . SVGTEST_PACKAGE . ' ' . $version . ' for the playground.',
            'require'           => [SVGTEST_PACKAGE => $version],
            'config'            => ['optimize-autoloader' => true],
            'minimum-stability' => 'stable',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );

    // Releases going back to 2015 declare PHP constraints that predate whatever
    // the host runs. The probe is the real compatibility test, so resolve the
    // lock regardless and let the probe decide whether the version works.
    $result = run([
        $composer,
        'update',
        '--no-install',
        '--no-interaction',
        '--no-progress',
        '--no-audit',
        '--ignore-platform-reqs',
        '--working-dir=' . $directory,
    ]);

    if ($result['code'] !== 0) {
        return ['ok' => false, 'output' => $result['output']];
    }

    return ['ok' => is_file($directory . '/composer.lock'), 'output' => $result['output']];
}

/** @return array{ok:bool,output:string} */
function installVendor(string $composer, string $directory): array
{
    if (!is_file($directory . '/composer.lock')) {
        return ['ok' => false, 'output' => 'no composer.lock committed for this version'];
    }

    $result = run([
        $composer,
        'install',
        '--no-dev',
        '--no-interaction',
        '--no-progress',
        '--no-audit',
        '--ignore-platform-reqs',
        '--optimize-autoloader',
        '--working-dir=' . $directory,
    ]);

    if ($result['code'] !== 0) {
        return ['ok' => false, 'output' => $result['output']];
    }

    return ['ok' => is_file($directory . '/vendor/autoload.php'), 'output' => $result['output']];
}

/** @return array{compatible:bool,error:?string,features:array<string,bool>,notices:array<int,mixed>} */
function probeVersion(string $version): array
{
    $result  = run([PHP_BINARY, dirname(__DIR__) . '/bin/probe.php', $version], 30);
    $decoded = extractProbePayload($result['output']);

    if ($decoded === null) {
        return [
            'compatible' => false,
            'error'      => 'Probe produced no usable output: ' . lastLine($result['output']),
            'features'   => [],
            'notices'    => [],
        ];
    }

    return [
        'compatible' => (bool) ($decoded['compatible'] ?? false),
        'error'      => $decoded['error'] ?? null,
        'features'   => is_array($decoded['features'] ?? null) ? $decoded['features'] : [],
        'notices'    => is_array($decoded['notices'] ?? null) ? $decoded['notices'] : [],
    ];
}

/**
 * Old code emits deprecation notices on its way past, so the payload is fenced
 * by sentinels rather than being assumed to be the whole of stdout.
 *
 * @return array<string,mixed>|null
 */
function extractProbePayload(string $output): ?array
{
    $start = strpos($output, PROBE_BEGIN);
    $end   = strrpos($output, PROBE_END);

    if ($start === false || $end === false || $end < $start) {
        return null;
    }

    $json    = substr($output, $start + strlen(PROBE_BEGIN), $end - $start - strlen(PROBE_BEGIN));
    $decoded = json_decode(trim($json), true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @param array<int,string> $command
 * @return array{code:int,output:string}
 */
function run(array $command, int $timeout = 300): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    $environment = getenv();
    $environment['COMPOSER_NO_INTERACTION'] = '1';

    if (!isset($environment['COMPOSER_HOME']) && !isset($environment['HOME'])) {
        $environment['COMPOSER_HOME'] = SVGTEST_STORAGE_DIR . '/composer-home';
    }

    $process = proc_open($command, $descriptors, $pipes, null, $environment);

    if (!is_resource($process)) {
        return ['code' => 1, 'output' => 'could not start ' . ($command[0] ?? '?')];
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $output   = '';
    $deadline = microtime(true) + $timeout;

    while (true) {
        $output .= (string) stream_get_contents($pipes[1]);
        $output .= (string) stream_get_contents($pipes[2]);

        if (!proc_get_status($process)['running']) {
            break;
        }

        if (microtime(true) > $deadline) {
            proc_terminate($process, 9);
            $output .= "\n[timed out after {$timeout}s]";
            break;
        }

        usleep(20000);
    }

    $output .= (string) stream_get_contents($pipes[1]);
    $output .= (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'output' => $output];
}

function locateComposer(): ?string
{
    $candidates = array_filter([
        getenv('COMPOSER_BIN') ?: null,
        '/usr/local/bin/composer',
        '/usr/bin/composer',
    ]);

    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    $found = trim((string) shell_exec('command -v composer 2>/dev/null'));

    return $found !== '' ? $found : null;
}

/** @return array<int,string> versions present on disk, newest first */
function localVersions(): array
{
    $versions = [];

    foreach (glob(SVGTEST_VERSIONS_DIR . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
        $version = basename($directory);

        if (VersionRegistry::isWellFormed($version) && is_file($directory . '/composer.json')) {
            $versions[] = $version;
        }
    }

    usort($versions, static fn (string $a, string $b): int => version_compare($b, $a));

    return $versions;
}

/**
 * @param array<int,string> $versions
 * @param array<string,string|bool> $options
 * @return array<int,string>
 */
function filterRequested(array $versions, array $options): array
{
    if (!isset($options['only'])) {
        return $versions;
    }

    $only = array_map('trim', explode(',', (string) $options['only']));

    return array_values(array_intersect($versions, $only));
}

/** @return array<string,array<string,mixed>> */
function readIndex(): array
{
    if (!is_readable(INDEX_PATH)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents(INDEX_PATH), true);
    $indexed = [];

    foreach ($decoded['versions'] ?? [] as $entry) {
        if (is_array($entry) && isset($entry['version'])) {
            $indexed[(string) $entry['version']] = $entry;
        }
    }

    return $indexed;
}

/** @param array<string,array<string,mixed>> $index */
function writeIndex(array $index): void
{
    uksort($index, static fn (string $a, string $b): int => version_compare($b, $a));

    file_put_contents(INDEX_PATH, json_encode([
        'package'  => SVGTEST_PACKAGE,
        'note'     => 'Written by bin/sync-versions.php --lock. Commit this alongside versions/*/composer.{json,lock}.',
        'versions' => array_values($index),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

function removeDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($directory);
}

/** Composer puts the useful part of a failure at the end, so report that. */
function lastLine(string $text): string
{
    $lines = array_values(array_filter(
        array_map('trim', explode("\n", $text)),
        static fn (string $line): bool => $line !== ''
    ));

    return $lines === [] ? 'no output' : substr((string) end($lines), 0, 300);
}

/** @return array<string,string|bool> */
function parseArguments(array $argv): array
{
    $options = [];

    foreach (array_slice($argv, 1) as $argument) {
        if (strpos($argument, '--') !== 0) {
            continue;
        }

        $argument = substr($argument, 2);
        $equals   = strpos($argument, '=');

        if ($equals === false) {
            $options[$argument] = true;
            continue;
        }

        $options[substr($argument, 0, $equals)] = substr($argument, $equals + 1);
    }

    return $options;
}
