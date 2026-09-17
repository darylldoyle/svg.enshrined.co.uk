<?php
/**
 * Renders one SVG inside an isolated document, for the before/after previews.
 *
 * A blob: or srcdoc: frame inherits the embedding page's CSP, which blocks the
 * preview's own stylesheet and — worse — any style attribute the SVG carries,
 * so the preview would not show what a browser actually draws. Serving it from
 * a real URL gives the frame its own policy.
 *
 * That policy starts with `sandbox`, which forces an opaque origin and blocks
 * scripts no matter how the document is loaded, so this stays inert even if
 * someone reaches it outside the playground.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header(
    "Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'; "
    . "img-src data:; font-src data:; frame-ancestors 'self'"
);

$svg = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['svg']) && is_string($_POST['svg'])) {
    $svg = $_POST['svg'];
}

if (strlen($svg) > SVGTEST_MAX_INPUT_BYTES) {
    $svg = '';
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Preview</title>
    <style>
        html, body { margin: 0; height: 100%; }

        body {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 12px;
            box-sizing: border-box;
            font: 13px ui-sans-serif, system-ui, sans-serif;
            color: #888;
        }

        svg { max-width: 100%; max-height: 100%; height: auto; }
    </style>
</head>
<body>
<?= $svg === '' ? 'Nothing to preview.' : $svg ?>
</body>
</html>
