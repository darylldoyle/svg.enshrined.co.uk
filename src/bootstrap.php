<?php
/**
 * Zero-dependency bootstrap. The app itself has no Composer dependencies —
 * each *library* version is installed separately under versions/<version>/.
 */

declare(strict_types=1);

define('SVGTEST_ROOT', dirname(__DIR__));
define('SVGTEST_VERSIONS_DIR', SVGTEST_ROOT . '/versions');
define('SVGTEST_STORAGE_DIR', SVGTEST_ROOT . '/storage');
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
