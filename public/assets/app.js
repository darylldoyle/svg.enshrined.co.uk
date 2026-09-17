/* ---------------------------------------------------------------------------
   SVG Sanitizer Playground

   No build step and no framework: the page arrives ready to go, and this file
   handles the editor, the call to the sanitizer, and the result views.
   --------------------------------------------------------------------------- */

(function () {
    'use strict';

    var boot = JSON.parse(document.getElementById('bootstrap').textContent);

    var OPTIONS = [
        {
            key: 'removeRemoteReferences',
            label: 'Remove remote references',
            hint: 'Strip url() values and hrefs that point off-site.',
            feature: 'removeRemoteReferences'
        },
        {
            key: 'minify',
            label: 'Minify output',
            hint: 'Collapse the cleaned document onto one line.',
            feature: 'minify'
        },
        {
            key: 'removeXMLTag',
            label: 'Remove XML declaration',
            hint: 'Drop the <?xml ... ?> prolog from the output.',
            feature: 'removeXMLTag'
        }
    ];

    var DIFF_CONTEXT = 3;
    var DIFF_ROW_CAP = 6000;

    /* --- State ------------------------------------------------------------ */

    var state = {
        version: null,
        options: Object.assign({}, boot.defaults),
        result: null,
        tab: 'diff',
        diffMode: 'split',
        collapse: true,
        wrap: true,
        ignoreWhitespace: true,
        expanded: new Set(),
        running: false,
        lastInput: ''
    };

    var usable = boot.versions.filter(function (v) { return v.installed && v.compatible; });

    var byVersion = {};
    boot.versions.forEach(function (v) { byVersion[v.version] = v; });

    var blobUrls = [];

    /* --- Elements --------------------------------------------------------- */

    var el = {
        themeToggle: byId('theme-toggle'),
        versionList: byId('version-list'),
        versionFilter: byId('version-filter'),
        versionCount: byId('version-count'),
        versionsReset: byId('versions-reset'),
        options: byId('options'),
        optionsReset: byId('options-reset'),
        samples: byId('samples'),
        input: byId('input'),
        editor: byId('editor'),
        gutter: byId('editor-gutter'),
        inputStats: byId('input-stats'),
        inputClear: byId('input-clear'),
        run: byId('run'),
        runShortcut: byId('run-shortcut'),
        runStatus: byId('run-status'),
        tabs: Array.prototype.slice.call(document.querySelectorAll('.tab')),
        empty: byId('results-empty'),
        actions: byId('results-actions'),
        issuesBadge: byId('issues-badge'),
        views: {
            diff: byId('view-diff'),
            output: byId('view-output'),
            preview: byId('view-preview'),
            issues: byId('view-issues')
        }
    };

    function byId(id) { return document.getElementById(id); }

    /* --- Helpers ---------------------------------------------------------- */

    function make(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined && text !== null) { node.textContent = String(text); }
        return node;
    }

    function clear(node) {
        while (node.firstChild) { node.removeChild(node.firstChild); }
    }

    function bytes(count) {
        if (count < 1024) { return count + ' B'; }
        if (count < 1048576) { return (count / 1024).toFixed(1) + ' KB'; }
        return (count / 1048576).toFixed(2) + ' MB';
    }

    function store(key, value) {
        try { localStorage.setItem('svgtest.' + key, JSON.stringify(value)); } catch (e) { /* private mode */ }
    }

    function recall(key, fallback) {
        try {
            var raw = localStorage.getItem('svgtest.' + key);
            return raw === null ? fallback : JSON.parse(raw);
        } catch (e) {
            return fallback;
        }
    }

    function compareVersions(a, b) {
        var pa = a.split('.').map(Number);
        var pb = b.split('.').map(Number);

        for (var i = 0; i < Math.max(pa.length, pb.length); i++) {
            var da = pa[i] || 0;
            var db = pb[i] || 0;
            if (da !== db) { return da - db; }
        }

        return 0;
    }

    /* --- Theme ------------------------------------------------------------ */

    function applyTheme(theme) {
        if (theme === 'dark' || theme === 'light') {
            document.documentElement.setAttribute('data-theme', theme);
        } else {
            document.documentElement.removeAttribute('data-theme');
        }
        store('theme', theme);
    }

    applyTheme(recall('theme', 'system'));

    el.themeToggle.addEventListener('click', function () {
        var current = document.documentElement.getAttribute('data-theme');
        var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        var showing = current || (prefersDark ? 'dark' : 'light');
        applyTheme(showing === 'dark' ? 'light' : 'dark');
    });

    /* --- Editor ----------------------------------------------------------- */

    function syncGutter() {
        var lines = el.input.value.split('\n').length;
        var text = '';
        for (var i = 1; i <= lines; i++) { text += i + '\n'; }

        el.gutter.textContent = text;
        el.gutter.scrollTop = el.input.scrollTop;

        var size = new Blob([el.input.value]).size;
        el.inputStats.textContent = lines + (lines === 1 ? ' line' : ' lines') + ' · ' + bytes(size);
    }

    el.input.addEventListener('input', function () {
        syncGutter();
        store('input', el.input.value);
        markActiveSample();
    });

    el.input.addEventListener('scroll', function () {
        el.gutter.scrollTop = el.input.scrollTop;
    });

    el.inputClear.addEventListener('click', function () {
        el.input.value = '';
        syncGutter();
        store('input', '');
        markActiveSample();
        el.input.focus();
    });

    /* --- Version picker --------------------------------------------------- */

    function renderVersions() {
        var filter = el.versionFilter.value.trim().toLowerCase();
        clear(el.versionList);

        var shown = boot.versions.filter(function (v) {
            return filter === '' || v.version.toLowerCase().indexOf(filter) !== -1;
        });

        if (shown.length === 0) {
            el.versionList.appendChild(make('p', 'hint version__empty', 'No versions match that filter.'));
        }

        shown.forEach(function (version) {
            var runnable = version.installed && version.compatible;
            var row = make('label', 'version' + (runnable ? '' : ' is-unusable'));

            var radio = document.createElement('input');
            radio.type = 'radio';
            radio.name = 'version';
            radio.value = version.version;
            radio.checked = state.version === version.version;
            radio.disabled = !runnable;

            if (radio.checked) { row.classList.add('is-selected'); }

            radio.addEventListener('change', function () {
                if (radio.checked) { selectVersion(version.version); }
            });

            row.appendChild(radio);
            row.appendChild(make('span', 'version__name', version.version));
            row.appendChild(make('span', 'version__date', version.released || ''));

            if (!runnable) { row.title = version.error || 'Not available on this server'; }

            el.versionList.appendChild(row);
        });

        var meta = byVersion[state.version];
        el.versionCount.textContent = meta
            ? 'Running ' + meta.version + (meta.released ? ' · released ' + meta.released : '')
            : usable.length + ' available';
    }

    function selectVersion(version) {
        state.version = version;
        store('version', version);

        // Results belong to the version that produced them.
        state.result = null;
        state.expanded = new Set();

        renderVersions();
        renderActive();
        setStatus('Switched to ' + version + '. Hit Sanitize to run it.');
    }

    el.versionFilter.addEventListener('input', renderVersions);

    el.versionsReset.addEventListener('click', function () {
        if (boot.latest) {
            el.versionFilter.value = '';
            selectVersion(boot.latest);
        }
    });

    /* --- Options ---------------------------------------------------------- */

    function renderOptions() {
        clear(el.options);

        OPTIONS.forEach(function (option) {
            var row = make('label', 'option');

            var text = make('span');
            text.appendChild(make('span', 'option__name', option.label));
            text.appendChild(make('span', 'option__hint', option.hint));
            row.appendChild(text);

            var field = document.createElement('input');
            field.type = 'checkbox';
            field.checked = Boolean(state.options[option.key]);
            field.addEventListener('change', function () {
                state.options[option.key] = field.checked;
                store('options', state.options);
            });

            row.appendChild(field);
            el.options.appendChild(row);
        });
    }

    el.optionsReset.addEventListener('click', function () {
        state.options = Object.assign({}, boot.defaults);
        store('options', state.options);
        renderOptions();
    });

    /* --- Samples ---------------------------------------------------------- */

    function renderSamples() {
        clear(el.samples);

        boot.samples.forEach(function (sample) {
            var button = make('button', 'sample');
            button.type = 'button';
            button.dataset.slug = sample.slug;
            button.appendChild(make('span', 'sample__title', sample.title));
            button.appendChild(make('span', 'sample__desc', sample.description));

            button.addEventListener('click', function () {
                el.input.value = sample.svg;
                syncGutter();
                store('input', sample.svg);
                markActiveSample();
                setStatus('Loaded the “' + sample.title + '” sample.');
            });

            el.samples.appendChild(button);
        });

        markActiveSample();
    }

    function markActiveSample() {
        var current = el.input.value;

        Array.prototype.forEach.call(el.samples.children, function (button) {
            var sample = boot.samples.filter(function (s) { return s.slug === button.dataset.slug; })[0];
            button.classList.toggle('is-active', Boolean(sample) && sample.svg === current);
        });
    }

    /* --- Running ---------------------------------------------------------- */

    function setStatus(message, isError) {
        el.runStatus.textContent = message || '';
        el.runStatus.style.color = isError ? 'var(--danger)' : '';
    }

    function request(payload) {
        return fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().catch(function () {
                return { ok: false, error: 'The server returned something that was not JSON (HTTP ' + response.status + ').' };
            });
        }).catch(function (error) {
            return { ok: false, error: 'Request failed: ' + error.message };
        });
    }

    function run() {
        if (state.running) { return; }

        var svg = el.input.value;

        if (svg.trim() === '') {
            setStatus('Paste or load an SVG first.', true);
            el.input.focus();
            return;
        }

        if (!state.version) {
            setStatus('Pick a version first.', true);
            return;
        }

        state.running = true;
        state.lastInput = svg;
        state.expanded = new Set();

        el.run.classList.add('is-busy');
        el.run.disabled = true;
        setStatus('Sanitizing with ' + state.version + '…');

        var startedAt = performance.now();

        request({
            action: 'sanitize',
            version: state.version,
            svg: svg,
            options: state.options,
            ignoreWhitespace: state.ignoreWhitespace
        }).then(function (result) {
            state.result = result;
            state.result.version = result.version || state.version;
            state.running = false;

            el.run.classList.remove('is-busy');
            el.run.disabled = false;

            var elapsed = Math.round(performance.now() - startedAt);

            if (!result.ok) {
                setStatus(result.error || 'That did not work.', true);
            } else if (result.unchanged) {
                setStatus('Done in ' + elapsed + ' ms · output identical to input');
            } else {
                setStatus('Done in ' + elapsed + ' ms');
            }

            renderActive();
        });
    }

    el.run.addEventListener('click', run);

    document.addEventListener('keydown', function (event) {
        if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
            event.preventDefault();
            run();
        }
    });

    /* --- Tabs ------------------------------------------------------------- */

    el.tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            selectTab(tab.dataset.tab);
        });
    });

    function selectTab(name) {
        state.tab = name;

        el.tabs.forEach(function (tab) {
            var on = tab.dataset.tab === name;
            tab.classList.toggle('is-active', on);
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
        });

        renderActive();
    }

    function renderActive() {
        Object.keys(el.views).forEach(function (name) {
            el.views[name].hidden = name !== state.tab;
            if (name !== state.tab) { clear(el.views[name]); }
        });

        clear(el.actions);
        revokeBlobs();

        var result = state.result;

        if (!result) {
            el.empty.hidden = state.running;
            clear(el.views[state.tab]);

            if (state.running) {
                el.views[state.tab].appendChild(make('div', 'notice', 'Running ' + state.version + '…'));
            }

            el.issuesBadge.hidden = true;
            return;
        }

        el.empty.hidden = true;

        var issueCount = result.issues ? result.issues.length : 0;
        el.issuesBadge.hidden = issueCount === 0;
        el.issuesBadge.textContent = String(issueCount);

        if (state.tab === 'diff') { renderDiffView(result); }
        if (state.tab === 'output') { renderOutputView(result); }
        if (state.tab === 'preview') { renderPreviewView(result); }
        if (state.tab === 'issues') { renderIssuesView(result); }
    }

    /* --- Shared pieces ---------------------------------------------------- */

    function summaryBar(result, stats) {
        var bar = make('div', 'summary');

        function stat(label, value, className) {
            var node = make('span', 'stat' + (className ? ' ' + className : ''));
            node.appendChild(make('b', null, value));
            node.appendChild(make('span', null, label));
            bar.appendChild(node);
        }

        if (stats) {
            if (stats.removed) { stat('removed', stats.removed, 'stat--del'); }
            if (stats.added) { stat('added', stats.added, 'stat--add'); }
            if (stats.changed) { stat('changed', stats.changed, 'stat--del'); }

            if (!stats.removed && !stats.added && !stats.changed) {
                var clean = make('span', 'stat stat--clean');
                clean.appendChild(make('b', null, '✓'));
                clean.appendChild(make('span', null, 'no changes'));
                bar.appendChild(clean);
            }
        }

        stat('in', bytes(result.inputBytes));
        stat('out', bytes(result.outputBytes));
        stat('ms', result.elapsedMs);

        if (result.issues && result.issues.length) {
            stat(result.issues.length === 1 ? 'XML issue' : 'XML issues', result.issues.length, 'stat--warn');
        }

        if (result.ignoredOptions && result.ignoredOptions.length) {
            var ignored = make('span', 'stat stat--warn');
            ignored.appendChild(make('b', null, result.ignoredOptions.length));
            ignored.appendChild(make('span', null, result.ignoredOptions.length === 1 ? 'option not in this version' : 'options not in this version'));
            ignored.title = 'Not available in ' + result.version + ': ' + result.ignoredOptions.join(', ');
            bar.appendChild(ignored);
        }

        return bar;
    }

    function errorNotice(result) {
        var notice = make('div', 'notice notice--error');
        notice.appendChild(make('strong', null, result.version + ' could not sanitize this. '));
        notice.appendChild(document.createTextNode(result.error || 'Unknown error.'));
        return notice;
    }

    /**
     * Releases going back to 2015 call functions modern PHP has deprecated. That
     * is worth seeing when you are testing a ten-year-old version, but it is not
     * a sanitizer failure, so it gets its own quiet note.
     */
    function phpNoticeBanner(result) {
        if (!result.phpNotices || result.phpNotices.length === 0) { return null; }

        var notice = make('div', 'notice notice--warn');
        notice.appendChild(make('strong', null, 'PHP ' + (result.phpNotices.length === 1 ? 'notice' : 'notices') + ' from this version: '));

        result.phpNotices.forEach(function (item, index) {
            if (index > 0) { notice.appendChild(document.createTextNode(' · ')); }
            notice.appendChild(document.createTextNode(item.message));

            if (item.source) {
                notice.appendChild(document.createTextNode(' ('));
                notice.appendChild(make('code', null, item.source));
                notice.appendChild(document.createTextNode(')'));
            }
        });

        return notice;
    }

    function copyButton(label, getText) {
        var button = make('button', 'button button--ghost', label);
        button.type = 'button';

        button.addEventListener('click', function () {
            var text = getText();
            if (text === null || text === undefined) { return; }

            navigator.clipboard.writeText(text).then(function () {
                button.textContent = 'Copied';
                setTimeout(function () { button.textContent = label; }, 1400);
            }).catch(function () {
                button.textContent = 'Copy failed';
                setTimeout(function () { button.textContent = label; }, 1400);
            });
        });

        return button;
    }

    function toggleChip(label, checked, onChange) {
        var chip = make('label', 'toggle-chip');
        var box = document.createElement('input');
        box.type = 'checkbox';
        box.checked = checked;
        box.addEventListener('change', function () { onChange(box.checked); });
        chip.appendChild(box);
        chip.appendChild(document.createTextNode(label));
        return chip;
    }

    /* --- Diff view -------------------------------------------------------- */

    function renderDiffView(result) {
        var view = el.views.diff;
        clear(view);

        if (!result.ok) {
            view.appendChild(errorNotice(result));
            return;
        }

        var mode = make('div', 'segmented');

        ['split', 'unified'].forEach(function (name) {
            var button = make('button', state.diffMode === name ? 'is-active' : '', name === 'split' ? 'Split' : 'Unified');
            button.type = 'button';
            button.addEventListener('click', function () {
                state.diffMode = name;
                store('diffMode', name);
                renderActive();
            });
            mode.appendChild(button);
        });

        el.actions.appendChild(mode);

        el.actions.appendChild(toggleChip('Collapse unchanged', state.collapse, function (on) {
            state.collapse = on;
            store('collapse', on);
            renderActive();
        }));

        el.actions.appendChild(toggleChip('Wrap lines', state.wrap, function (on) {
            state.wrap = on;
            store('wrap', on);
            renderActive();
        }));

        el.actions.appendChild(toggleChip('Ignore whitespace', state.ignoreWhitespace, function (on) {
            state.ignoreWhitespace = on;
            store('ignoreWhitespace', on);
            reloadDiff();
        }));

        if (!result.diff) {
            view.appendChild(make('div', 'notice', 'Building the diff…'));
            reloadDiff();
            return;
        }

        view.appendChild(summaryBar(result, result.diff.stats));

        var phpNotice = phpNoticeBanner(result);
        if (phpNotice) { view.appendChild(phpNotice); }

        if (result.diff.truncated) {
            view.appendChild(make(
                'div',
                'notice notice--warn',
                'These documents differ too much to align line by line, so parts are shown as a wholesale replacement.'
            ));
        }

        view.appendChild(buildDiff(result.diff.rows, 'Input', result.version + ' output'));
    }

    /** Re-diff the stored result without sanitizing again. */
    function reloadDiff() {
        var result = state.result;
        if (!result || !result.ok || result.clean === null) { return; }

        request({
            action: 'diff',
            before: state.lastInput,
            after: result.clean,
            ignoreWhitespace: state.ignoreWhitespace
        }).then(function (response) {
            if (response.ok && response.diff) {
                result.diff = response.diff;
                state.expanded = new Set();
                renderActive();
            }
        });
    }

    function buildDiff(rows, labelBefore, labelAfter) {
        var container = make('div', 'diff diff--' + state.diffMode + (state.wrap ? ' diff--wrap' : ' diff--nowrap'));

        var header = make('div', 'diff__header');
        header.appendChild(make('span', null, labelBefore));
        if (state.diffMode === 'split') { header.appendChild(make('span', null, labelAfter)); }
        container.appendChild(header);

        var blocks = state.collapse ? foldRows(rows) : [{ type: 'rows', rows: rows }];
        var rendered = 0;
        var capped = false;
        var widest = 0;

        blocks.forEach(function (block, index) {
            if (block.type === 'fold' && !state.expanded.has(index)) {
                var fold = make('button', 'diff__fold');
                fold.type = 'button';
                fold.textContent = '⋯  ' + block.rows.length + ' unchanged lines';
                fold.addEventListener('click', function () {
                    state.expanded.add(index);
                    renderActive();
                });
                container.appendChild(fold);
                return;
            }

            block.rows.forEach(function (row) {
                if (rendered >= DIFF_ROW_CAP) { capped = true; return; }

                widest = Math.max(widest, (row.a || '').length, (row.b || '').length);
                container.appendChild(buildRow(row));
                rendered++;
            });
        });

        if (capped) {
            container.appendChild(make(
                'div',
                'notice notice--warn',
                'Showing the first ' + DIFF_ROW_CAP.toLocaleString() + ' lines. Download the output to see the rest.'
            ));
        }

        if (!state.wrap) { attachSharedScrollbar(container, widest); }

        return container;
    }

    /**
     * One scrollbar for the whole diff.
     *
     * Letting each line scroll on its own means the two halves of a comparison
     * drift apart, so instead a single track drives a translate on every code
     * cell at once: both columns move together and stay side by side.
     */
    function attachSharedScrollbar(container, widestLine) {
        var track = make('div', 'diff__hscroll');
        var spacer = make('div', 'diff__hscroll-spacer');

        // The diff is set in a monospace face, so one ch is one character and
        // the widest line's width needs no measuring.
        spacer.style.width = 'calc(' + widestLine + 'ch + 24px)';

        track.appendChild(spacer);
        container.appendChild(track);

        track.addEventListener('scroll', function () {
            container.style.setProperty('--scroll-x', track.scrollLeft + 'px');
        });

        // Trackpad and shift-wheel gestures land on the diff, not on the track.
        container.addEventListener('wheel', function (event) {
            var horizontal = event.shiftKey ? event.deltaY : event.deltaX;
            if (Math.abs(horizontal) < 1) { return; }

            var before = track.scrollLeft;
            track.scrollLeft += horizontal;

            if (track.scrollLeft !== before) { event.preventDefault(); }
        }, { passive: false });
    }

    /**
     * Collapse long runs of identical lines, keeping a few lines of context on
     * either side so a change never appears without its surroundings.
     */
    function foldRows(rows) {
        var blocks = [];
        var run = [];

        function flushRun() {
            if (run.length === 0) { return; }

            if (run.length > DIFF_CONTEXT * 2 + 2) {
                blocks.push({ type: 'rows', rows: run.slice(0, DIFF_CONTEXT) });
                blocks.push({ type: 'fold', rows: run.slice(DIFF_CONTEXT, run.length - DIFF_CONTEXT) });
                blocks.push({ type: 'rows', rows: run.slice(run.length - DIFF_CONTEXT) });
            } else {
                blocks.push({ type: 'rows', rows: run });
            }

            run = [];
        }

        rows.forEach(function (row) {
            if (row.op === 'equal') {
                run.push(row);
                return;
            }

            flushRun();

            var last = blocks[blocks.length - 1];

            if (last && last.type === 'rows' && last.changed) {
                last.rows.push(row);
            } else {
                blocks.push({ type: 'rows', rows: [row], changed: true });
            }
        });

        flushRun();

        return blocks;
    }

    function buildRow(row) {
        if (state.diffMode === 'unified') { return buildUnifiedRow(row); }

        var node = make('div', 'diff__row');

        if (row.op === 'equal') {
            node.appendChild(buildSide('', row.aLine, row.a, null, null));
            node.appendChild(buildSide('', row.bLine, row.b, null, null));
        } else if (row.op === 'delete') {
            node.appendChild(buildSide('del', row.aLine, row.a, null, null));
            node.appendChild(buildSide('empty', null, '', null, null));
        } else if (row.op === 'insert') {
            node.appendChild(buildSide('empty', null, '', null, null));
            node.appendChild(buildSide('add', row.bLine, row.b, null, null));
        } else {
            node.appendChild(buildSide('del', row.aLine, row.a, row.aParts, null));
            node.appendChild(buildSide('add', row.bLine, row.b, row.bParts, null));
        }

        return node;
    }

    function buildUnifiedRow(row) {
        if (row.op === 'equal') {
            return wrapSide(buildSide('', row.aLine, row.a, null, ' '));
        }

        if (row.op === 'delete') {
            return wrapSide(buildSide('del', row.aLine, row.a, null, '-'));
        }

        if (row.op === 'insert') {
            return wrapSide(buildSide('add', row.bLine, row.b, null, '+'));
        }

        // A changed line reads as a removal immediately followed by its addition.
        var fragment = document.createDocumentFragment();
        fragment.appendChild(wrapSide(buildSide('del', row.aLine, row.a, row.aParts, '-')));
        fragment.appendChild(wrapSide(buildSide('add', row.bLine, row.b, row.bParts, '+')));

        return fragment;
    }

    function wrapSide(side) {
        var node = make('div', 'diff__row');
        node.appendChild(side);
        return node;
    }

    function buildSide(kind, line, text, parts, sign) {
        var side = make('div', 'diff__side' + (kind ? ' diff__side--' + kind : ''));

        side.appendChild(make('span', 'diff__num', line === null || line === undefined ? '' : line));

        var cell = make('span', 'diff__code');
        var inner = make('span', 'diff__codeinner');

        if (sign) { inner.appendChild(make('span', 'diff__sign', sign)); }

        if (parts && parts.length) {
            parts.forEach(function (part) {
                if (part[0] === 'diff') {
                    inner.appendChild(make('mark', null, part[1]));
                } else {
                    inner.appendChild(document.createTextNode(part[1]));
                }
            });
        } else {
            inner.appendChild(document.createTextNode(text === null || text === undefined ? '' : text));
        }

        cell.appendChild(inner);
        side.appendChild(cell);

        return side;
    }

    /* --- Output view ------------------------------------------------------ */

    function renderOutputView(result) {
        var view = el.views.output;
        clear(view);

        if (!result.ok) {
            view.appendChild(errorNotice(result));
            return;
        }

        el.actions.appendChild(copyButton('Copy', function () { return result.clean; }));

        var download = make('button', 'button button--ghost', 'Download');
        download.type = 'button';
        download.addEventListener('click', function () {
            var url = URL.createObjectURL(new Blob([result.clean], { type: 'image/svg+xml' }));
            var link = document.createElement('a');
            link.href = url;
            link.download = 'sanitized-' + result.version + '.svg';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
        });
        el.actions.appendChild(download);

        el.actions.appendChild(toggleChip('Wrap lines', state.wrap, function (on) {
            state.wrap = on;
            store('wrap', on);
            renderActive();
        }));

        view.appendChild(summaryBar(result, result.diff ? result.diff.stats : null));

        var phpNotice = phpNoticeBanner(result);
        if (phpNotice) { view.appendChild(phpNotice); }

        if (result.unchanged) {
            view.appendChild(make('div', 'notice', 'The output is byte-identical to the input — this version changed nothing.'));
        }

        view.appendChild(codeBlock(result.clean));
    }

    function codeBlock(text) {
        var wrap = make('div', 'code' + (state.wrap ? ' code--wrap' : ''));
        var lines = text.split('\n');

        var numbers = '';
        for (var i = 1; i <= lines.length; i++) { numbers += i + '\n'; }

        wrap.appendChild(make('div', 'code__gutter', numbers));
        wrap.appendChild(make('pre', 'code__body', text));

        return wrap;
    }

    /* --- Preview view ----------------------------------------------------- */

    function renderPreviewView(result) {
        var view = el.views.preview;
        clear(view);

        var grid = make('div', 'preview-grid');
        grid.appendChild(previewPane('Input', state.lastInput, 'Rendered in a sandboxed frame with scripts and network access blocked.'));

        if (result.ok) {
            grid.appendChild(previewPane(result.version + ' output', result.clean, 'What a browser would draw after sanitizing.'));
        } else {
            var failed = make('div', 'preview');
            failed.appendChild(make('div', 'preview__head', 'Output'));
            failed.appendChild(errorNotice(result));
            grid.appendChild(failed);
        }

        view.appendChild(grid);
    }

    function previewPane(label, svg, note) {
        var pane = make('div', 'preview');

        var head = make('div', 'preview__head');
        head.appendChild(make('span', null, label));
        pane.appendChild(head);

        var stage = make('div', 'preview__stage');
        var frame = document.createElement('iframe');
        frame.setAttribute('sandbox', '');
        frame.setAttribute('referrerpolicy', 'no-referrer');
        frame.title = label + ' preview';
        frame.src = previewUrl(svg);
        stage.appendChild(frame);
        pane.appendChild(stage);

        pane.appendChild(make('div', 'preview__note', note));

        return pane;
    }

    /**
     * The preview is deliberately paranoid: an opaque sandboxed frame, so no
     * scripts and no access to this page, wrapped in a policy that blocks every
     * outbound request. A payload that survives sanitizing still cannot run or
     * phone home from in there.
     */
    function previewUrl(svg) {
        var page = '<!doctype html><html><head><meta charset="utf-8">'
            + '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data:; font-src data:">'
            + '<style>html,body{margin:0;height:100%}'
            + 'body{display:flex;align-items:center;justify-content:center;padding:12px;box-sizing:border-box}'
            + 'svg{max-width:100%;max-height:100%;height:auto}</style>'
            + '</head><body>' + svg + '</body></html>';

        var url = URL.createObjectURL(new Blob([page], { type: 'text/html' }));
        blobUrls.push(url);

        return url;
    }

    function revokeBlobs() {
        blobUrls.forEach(function (url) { URL.revokeObjectURL(url); });
        blobUrls = [];
    }

    /* --- Issues view ------------------------------------------------------ */

    function renderIssuesView(result) {
        var view = el.views.issues;
        clear(view);

        if (!result.ok) {
            view.appendChild(errorNotice(result));
            return;
        }

        var meta = byVersion[result.version];

        if (meta && meta.features && !meta.features.getXmlIssues) {
            view.appendChild(make('div', 'notice', 'Version ' + result.version + ' predates getXmlIssues(), so it cannot report parser issues.'));
            return;
        }

        if (!result.issues || result.issues.length === 0) {
            view.appendChild(make('div', 'notice', 'No XML parser issues were reported.'));
            return;
        }

        var list = make('div', 'issues');

        result.issues.forEach(function (issue) {
            var item = make('div', 'issue');
            item.appendChild(make('span', 'issue__line', issue.line ? 'line ' + issue.line : '—'));
            item.appendChild(make('span', 'issue__message', issue.message));
            list.appendChild(item);
        });

        view.appendChild(list);
    }

    /* --- Deep links -------------------------------------------------------
       ?sample=script-injection&v=0.15.4&run=1
       Enough to hand someone a link that reproduces what you saw.
       ---------------------------------------------------------------------- */

    function applyQuery() {
        var params = new URLSearchParams(window.location.search);
        var applied = {};

        var version = params.get('v');
        var meta = version ? byVersion[version] : null;

        if (meta && meta.installed && meta.compatible) {
            state.version = version;
            applied.version = true;
        }

        var slug = params.get('sample');

        if (slug) {
            var sample = boot.samples.filter(function (s) { return s.slug === slug; })[0];
            if (sample) { el.input.value = sample.svg; }
        }

        applied.run = params.get('run') === '1';

        return applied;
    }

    /* --- Boot ------------------------------------------------------------- */

    function init() {
        var saved = recall('version', null);

        if (typeof saved === 'string' && byVersion[saved] && byVersion[saved].installed && byVersion[saved].compatible) {
            state.version = saved;
        } else {
            state.version = boot.latest;
        }

        var savedOptions = recall('options', null);

        if (savedOptions && typeof savedOptions === 'object') {
            Object.keys(boot.defaults).forEach(function (key) {
                if (Object.prototype.hasOwnProperty.call(savedOptions, key)) {
                    state.options[key] = savedOptions[key];
                }
            });
        }

        state.diffMode = recall('diffMode', 'split');
        state.collapse = recall('collapse', true);
        state.wrap = recall('wrap', true);
        state.ignoreWhitespace = recall('ignoreWhitespace', true);

        var savedInput = recall('input', null);
        el.input.value = (typeof savedInput === 'string' && savedInput !== '')
            ? savedInput
            : (boot.samples.length ? boot.samples[0].svg : '');

        var fromQuery = applyQuery();

        state.lastInput = el.input.value;

        var isMac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);
        el.runShortcut.textContent = isMac ? '⌘⏎' : 'Ctrl⏎';

        syncGutter();
        renderVersions();
        renderOptions();
        renderSamples();
        renderActive();

        if (boot.latest === null) {
            setStatus('No versions are installed on this server yet.', true);
            el.run.disabled = true;
            return;
        }

        if (fromQuery.run) { run(); }
    }

    init();
}());
