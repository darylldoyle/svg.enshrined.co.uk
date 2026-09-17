<?php

declare(strict_types=1);

namespace SvgTest;

use Throwable;

/**
 * Runs one SVG through one installed version of the library.
 *
 * Every version declares the same `enshrined\svgSanitize\Sanitizer` class, so a
 * process can only ever hold one of them. That is why a request handles exactly
 * one version and the browser fans out across versions in parallel — each
 * response is served by its own PHP worker, which keeps the versions isolated
 * and stops one version's fatal from taking the others down.
 */
final class SanitizeService
{
    /** Options a caller may set, with their defaults. */
    public const OPTION_DEFAULTS = [
        'removeRemoteReferences' => true,
        'minify'                 => false,
        'removeXMLTag'           => false,
    ];

    /**
     * Applied on every run and deliberately not exposed to callers. Lifting the
     * `<use>` limits or letting libxml parse without bounds turns a public
     * playground into somebody else's denial-of-service tool.
     */
    private const FIXED_OPTIONS = [
        'setAllowHugeFiles'   => false,
        'useThreshold'        => 1000,
        'setUseNestingLimit'  => 15,
    ];

    /** Option name => the Sanitizer method that applies it. */
    private const OPTION_METHODS = [
        'removeRemoteReferences' => 'removeRemoteReferences',
        'minify'                 => 'minify',
        'removeXMLTag'           => 'removeXMLTag',
    ];

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function run(string $version, string $dirty, array $options): array
    {
        $autoload = VersionRegistry::autoloadPath($version);

        if (!is_file($autoload)) {
            return $this->failure($version, 'This version is not installed on the server.');
        }

        require_once $autoload;

        if (!class_exists('enshrined\svgSanitize\Sanitizer')) {
            return $this->failure($version, 'The Sanitizer class could not be loaded from this version.');
        }

        $sanitizer = new \enshrined\svgSanitize\Sanitizer();
        $applied   = $this->applyOptions($sanitizer, $options);

        $startedAt   = microtime(true);
        $startMemory = memory_get_usage();

        $clean  = null;
        $failed = null;

        try {
            $result = $sanitizer->sanitize($dirty);
            // The library returns false when libxml cannot parse the document.
            $clean = $result === false ? null : (string) $result;
            if ($clean === null) {
                $failed = 'The sanitizer could not parse this document and returned false.';
            }
        } catch (Throwable $e) {
            $failed = get_class($e) . ': ' . $e->getMessage();
        }

        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        return [
            'version'         => $version,
            'ok'              => $failed === null,
            'error'           => $failed,
            'clean'           => $clean,
            'issues'          => $this->issues($sanitizer),
            'appliedOptions'  => $applied['applied'],
            'ignoredOptions'  => $applied['ignored'],
            'elapsedMs'       => round($elapsedMs, 2),
            'memoryBytes'     => max(0, memory_get_usage() - $startMemory),
            'peakMemoryBytes' => memory_get_peak_usage(),
            'inputBytes'      => strlen($dirty),
            'outputBytes'     => $clean === null ? 0 : strlen($clean),
            'unchanged'       => $clean !== null && $clean === $dirty,
        ];
    }

    /**
     * Options arrived over time, so ask each version what it actually supports
     * rather than assuming. Anything unsupported is reported back to the UI.
     *
     * @param array<string,mixed> $options
     * @return array{applied:array<string,mixed>,ignored:array<int,string>}
     */
    private function applyOptions(object $sanitizer, array $options): array
    {
        $applied = [];
        $ignored = [];

        foreach (self::FIXED_OPTIONS as $method => $value) {
            if (method_exists($sanitizer, $method)) {
                $sanitizer->{$method}($value);
            }
        }

        foreach (self::OPTION_METHODS as $option => $method) {
            if (!array_key_exists($option, $options)) {
                continue;
            }

            if (!method_exists($sanitizer, $method)) {
                $ignored[] = $option;
                continue;
            }

            $value = $options[$option];
            $sanitizer->{$method}($value);
            $applied[$option] = $value;
        }

        return ['applied' => $applied, 'ignored' => $ignored];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function issues(object $sanitizer): array
    {
        if (!method_exists($sanitizer, 'getXmlIssues')) {
            return [];
        }

        $issues = $sanitizer->getXmlIssues();

        if (!is_array($issues)) {
            return [];
        }

        return array_map(static function ($issue): array {
            if (!is_array($issue)) {
                return ['message' => (string) $issue, 'line' => null];
            }

            return [
                'message' => isset($issue['message']) ? trim((string) $issue['message']) : '',
                'line'    => isset($issue['line']) ? (int) $issue['line'] : null,
            ];
        }, array_values($issues));
    }

    /** @return array<string,mixed> */
    private function failure(string $version, string $message): array
    {
        return [
            'version'         => $version,
            'ok'              => false,
            'error'           => $message,
            'clean'           => null,
            'issues'          => [],
            'appliedOptions'  => [],
            'ignoredOptions'  => [],
            'elapsedMs'       => 0.0,
            'memoryBytes'     => 0,
            'peakMemoryBytes' => 0,
            'inputBytes'      => 0,
            'outputBytes'     => 0,
            'unchanged'       => false,
        ];
    }

    /**
     * Normalise whatever the client sent into the option shape we support.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function normaliseOptions(array $input): array
    {
        $options = [];

        foreach (self::OPTION_DEFAULTS as $name => $default) {
            $value = array_key_exists($name, $input) ? $input[$name] : $default;

            if (is_bool($default)) {
                $options[$name] = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
                continue;
            }

            $options[$name] = is_numeric($value) ? max(0, (int) $value) : $default;
        }

        return $options;
    }
}
