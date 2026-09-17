<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use SvgTest\SanitizeService;
use SvgTest\Samples;
use SvgTest\VersionRegistry;

$registry = new VersionRegistry();
$samples  = new Samples();

$latest = $registry->latest();

$bootstrap = [
    'package'     => SVGTEST_PACKAGE,
    'repository'  => 'https://github.com/darylldoyle/svg-sanitizer',
    'versions'    => $registry->all(),
    'latest'      => $latest,
    'generatedAt' => $registry->generatedAt(),
    'samples'     => $samples->all(),
    'defaults'    => SanitizeService::OPTION_DEFAULTS,
    'maxBytes'    => SVGTEST_MAX_INPUT_BYTES,
];

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header(
    "Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; "
    . "img-src 'self' data:; frame-src blob:; connect-src 'self'; object-src 'none'; "
    . "base-uri 'none'; form-action 'none'; frame-ancestors 'none'"
);

/** Cache-bust assets on change without inventing a build step. */
$asset = static function (string $file): string {
    $path = __DIR__ . '/assets/' . $file;

    return htmlspecialchars('assets/' . $file . '?v=' . (is_file($path) ? (string) filemtime($path) : '0'), ENT_QUOTES);
};

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>SVG Sanitizer Playground</title>
    <meta name="description" content="Paste an SVG and watch svg-sanitize clean it, with a line-by-line diff and a comparison across every released version.">
    <meta name="color-scheme" content="dark light">
    <link rel="icon" href="<?= $asset('favicon.svg') ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= $asset('app.css') ?>">
</head>
<body>

<a class="skip-link" href="#results">Skip to results</a>

<header class="topbar">
    <div class="topbar__brand">
        <svg class="topbar__mark" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
            <path d="M16 3 5 7.5v8.2c0 6.8 4.6 11.6 11 13.3 6.4-1.7 11-6.5 11-13.3V7.5Z" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round"/>
            <path d="m11.5 16.2 3.2 3.3 6-6.6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <div class="topbar__text">
            <h1>SVG Sanitizer Playground</h1>
            <p>Paste something nasty. See exactly what comes back out.</p>
        </div>
    </div>

    <div class="topbar__meta">
        <?php if ($latest !== null) : ?>
            <span class="tag tag--accent" title="Newest version installed on this server">v<?= htmlspecialchars($latest, ENT_QUOTES) ?></span>
            <span class="tag" title="Versions available to run against"><?= count($registry->usable()) ?> versions</span>
        <?php endif; ?>
        <a class="tag tag--link" href="https://github.com/darylldoyle/svg-sanitizer" target="_blank" rel="noopener noreferrer">
            GitHub
            <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M6 3h7v7M13 3 4 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </a>
        <button type="button" class="icon-button" id="theme-toggle" aria-label="Switch colour theme" title="Switch colour theme">
            <svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M16 11.5A6.8 6.8 0 0 1 8.5 4a1 1 0 0 0-1.4-1A7.8 7.8 0 1 0 17 12.9a1 1 0 0 0-1-1.4Z" fill="currentColor"/></svg>
        </button>
    </div>
</header>

<?php if ($latest === null) : ?>
    <div class="banner" role="alert">
        <strong>No library versions are installed yet.</strong>
        Run <code>php bin/sync-versions.php</code> from the project root, then reload.
    </div>
<?php endif; ?>

<main class="layout">

    <aside class="sidebar" aria-label="Run settings">
        <section class="panel">
            <div class="panel__head">
                <h2>Versions</h2>
                <button type="button" class="link-button" id="versions-reset">Latest</button>
            </div>

            <label class="field">
                <span class="visually-hidden">Filter versions</span>
                <input type="search" id="version-filter" placeholder="Filter&hellip;" autocomplete="off" spellcheck="false">
            </label>

            <div class="version-list" id="version-list" role="radiogroup" aria-label="Library version"></div>

            <div class="panel__foot">
                <span class="hint" id="version-count"></span>
            </div>
        </section>

        <section class="panel">
            <div class="panel__head">
                <h2>Options</h2>
                <button type="button" class="link-button" id="options-reset">Reset</button>
            </div>

            <div class="options" id="options"></div>
        </section>

        <section class="panel">
            <div class="panel__head">
                <h2>Samples</h2>
            </div>
            <div class="samples" id="samples"></div>
        </section>
    </aside>

    <div class="workspace">

        <section class="pane pane--input" aria-labelledby="input-heading">
            <header class="pane__head">
                <h2 id="input-heading">Input</h2>
                <div class="pane__actions">
                    <span class="hint" id="input-stats"></span>
                    <button type="button" class="button button--ghost" id="input-clear">Clear</button>
                </div>
            </header>

            <div class="editor" id="editor">
                <div class="editor__gutter" id="editor-gutter" aria-hidden="true"></div>
                <textarea id="input"
                          spellcheck="false"
                          autocapitalize="off"
                          autocorrect="off"
                          autocomplete="off"
                          wrap="off"
                          aria-label="Dirty SVG source"
                          placeholder="Paste your SVG here."></textarea>
            </div>

            <footer class="pane__foot">
                <button type="button" class="button button--primary" id="run">
                    <span>Sanitize</span>
                    <kbd id="run-shortcut"></kbd>
                </button>
                <span class="hint" id="run-status" role="status" aria-live="polite"></span>
            </footer>
        </section>

        <section class="pane pane--results" id="results" aria-labelledby="results-heading">
            <header class="pane__head pane__head--tabs">
                <h2 id="results-heading" class="visually-hidden">Results</h2>

                <div class="tabs" role="tablist" aria-label="Result views">
                    <button type="button" role="tab" class="tab is-active" data-tab="diff" aria-selected="true">Diff</button>
                    <button type="button" role="tab" class="tab" data-tab="output" aria-selected="false">Output</button>
                    <button type="button" role="tab" class="tab" data-tab="preview" aria-selected="false">Preview</button>
                    <button type="button" role="tab" class="tab" data-tab="issues" aria-selected="false">Issues<span class="tab__badge" id="issues-badge" hidden>0</span></button>
                </div>

                <div class="pane__actions" id="results-actions"></div>
            </header>

            <div class="results-body">
                <div class="empty" id="results-empty">
                    <svg viewBox="0 0 48 48" aria-hidden="true" focusable="false">
                        <path d="M24 6 9 12v12c0 9.4 6.3 16 15 18.4C32.7 40 39 33.4 39 24V12Z" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linejoin="round"/>
                        <path d="m17.5 24.3 4.8 4.9 9-9.9" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <p>Nothing sanitized yet.</p>
                    <p class="hint">Pick a version, then hit Sanitize.</p>
                </div>

                <div class="view" id="view-diff" role="tabpanel" hidden></div>
                <div class="view" id="view-output" role="tabpanel" hidden></div>
                <div class="view" id="view-preview" role="tabpanel" hidden></div>
                <div class="view" id="view-issues" role="tabpanel" hidden></div>
            </div>
        </section>

    </div>
</main>

<footer class="sitefoot">
    <p>
        Built on <a href="https://github.com/darylldoyle/svg-sanitizer" target="_blank" rel="noopener noreferrer">svg-sanitize</a>,
        the library behind <a href="https://wordpress.org/plugins/safe-svg/" target="_blank" rel="noopener noreferrer">Safe SVG</a>.
        Found something that gets through?
        <a href="https://github.com/darylldoyle/svg-sanitizer/issues/new" target="_blank" rel="noopener noreferrer">Open an issue</a>.
    </p>
    <p class="hint">
        PHP <?= htmlspecialchars(PHP_VERSION, ENT_QUOTES) ?>
        <?php if ($registry->generatedAt() !== null) : ?>
            &middot; versions synced <?= htmlspecialchars(substr((string) $registry->generatedAt(), 0, 10), ENT_QUOTES) ?>
        <?php endif; ?>
    </p>
</footer>

<script type="application/json" id="bootstrap"><?= json_encode($bootstrap, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= $asset('app.js') ?>" defer></script>
</body>
</html>
