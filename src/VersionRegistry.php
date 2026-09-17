<?php

declare(strict_types=1);

namespace SvgTest;

/**
 * Reads the manifest written by bin/sync-versions.php and answers questions
 * about which library versions are installed and usable.
 */
final class VersionRegistry
{
    private const MANIFEST = SVGTEST_STORAGE_DIR . '/versions.json';

    /** @var array<string,mixed>|null */
    private ?array $manifest = null;

    /** @return array<string,mixed> */
    public function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $manifest = null;

        if (is_readable(self::MANIFEST)) {
            $decoded = json_decode((string) file_get_contents(self::MANIFEST), true);
            if (is_array($decoded)) {
                $manifest = $decoded;
            }
        }

        return $this->manifest = $manifest ?? [
            'generated_at' => null,
            'latest'       => null,
            'versions'     => [],
        ];
    }

    /**
     * Every version we know about, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function all(): array
    {
        $versions = $this->manifest()['versions'] ?? [];

        return is_array($versions) ? array_values($versions) : [];
    }

    /**
     * Versions that are installed and passed their smoke test.
     *
     * @return array<int,array<string,mixed>>
     */
    public function usable(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (array $v): bool => ($v['installed'] ?? false) && ($v['compatible'] ?? false)
        ));
    }

    /** @return array<string,mixed>|null */
    public function find(string $version): ?array
    {
        foreach ($this->all() as $entry) {
            if (($entry['version'] ?? null) === $version) {
                return $entry;
            }
        }

        return null;
    }

    public function isUsable(string $version): bool
    {
        $entry = $this->find($version);

        return $entry !== null && ($entry['installed'] ?? false) && ($entry['compatible'] ?? false);
    }

    /** The newest usable version, which the UI selects by default. */
    public function latest(): ?string
    {
        $latest = $this->manifest()['latest'] ?? null;

        if (is_string($latest) && $this->isUsable($latest)) {
            return $latest;
        }

        $usable = $this->usable();

        return $usable === [] ? null : (string) $usable[0]['version'];
    }

    public function generatedAt(): ?string
    {
        $value = $this->manifest()['generated_at'] ?? null;

        return is_string($value) ? $value : null;
    }

    /** Absolute path to a version's Composer autoloader. */
    public static function autoloadPath(string $version): string
    {
        return self::installPath($version) . '/vendor/autoload.php';
    }

    /** Where a version's vendor tree is installed, outside the release directory. */
    public static function installPath(string $version): string
    {
        return SVGTEST_INSTALL_DIR . '/' . $version;
    }

    /**
     * Versions are untrusted input from the browser, so keep the character set
     * tight enough that it can never escape the versions/ directory.
     */
    public static function isWellFormed(string $version): bool
    {
        return (bool) preg_match('/^[0-9]+(\.[0-9]+)*(-[A-Za-z0-9.]+)?$/', $version);
    }
}
