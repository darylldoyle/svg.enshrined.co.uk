<?php
/**
 * Zero-dependency bootstrap. The app itself has no Composer dependencies —
 * each *library* version is installed separately under versions/<version>/.
 */

declare(strict_types=1);

define('SVGTEST_ROOT', dirname(__DIR__));

/** Pinned composer.json/composer.lock per release, tracked in git. */
define('SVGTEST_VERSIONS_DIR', SVGTEST_ROOT . '/versions');

/**
 * Everything generated on the host. Under a zero-downtime deploy this is the
 * one directory shared between releases, so the installed library trees survive
 * a deploy instead of being rebuilt 49 times over.
 */
define('SVGTEST_STORAGE_DIR', SVGTEST_ROOT . '/storage');

/** Where each version's vendor tree is installed. */
define('SVGTEST_INSTALL_DIR', SVGTEST_STORAGE_DIR . '/versions');
define('SVGTEST_SAMPLES_DIR', SVGTEST_ROOT . '/samples');
define('SVGTEST_PACKAGE', 'enshrined/svg-sanitize');

/** Hard ceiling on the SVG payload we will accept, in bytes. */
define('SVGTEST_MAX_INPUT_BYTES', 2 * 1024 * 1024);

spl_autoload_register(static function (string $class): void {
    if (strpos($class, 'SvgTest\\') !== 0) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen('SvgTest\\')));
    $file     = __DIR__ . '/' . $relative . '.php';

    if (is_file($file)) {
        require $file;
    }
});
