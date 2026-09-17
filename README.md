# SVG Sanitizer Playground

The site behind <https://svg.enshrined.co.uk>. Paste an SVG, run it through any
released version of [`enshrined/svg-sanitize`][lib], and see exactly what the
sanitizer did to it — as a line-by-line diff, as the cleaned source, as a
rendered preview, and as the list of issues the XML parser raised.

[lib]: https://github.com/darylldoyle/svg-sanitizer

## How it fits together

```
public/            Document root — the only directory the web server exposes
  index.php        Page shell; hands versions, samples and defaults to the JS
  api.php          JSON endpoint: sanitize, and diff
  preview.php      Renders one SVG in an isolated, script-free document
  assets/          One stylesheet, one script, no build step
src/               Application code, autoloaded from src/bootstrap.php
  Diff.php         Myers line diff with word-level refinement
  SanitizeService.php
  VersionRegistry.php
  Samples.php
bin/
  sync-versions.php  Discovers, locks, installs and probes library versions
  probe.php          Runs one version in its own process to test it
samples/           The payloads offered in the sidebar
versions/          One pinned Composer install per released version
storage/           Generated manifest (gitignored)
```

The playground itself has no Composer dependencies. Each *library* version lives
in its own `versions/<version>/` install, so a request loads exactly one of them.

### Why one version per request

Every release ships the same `enshrined\svgSanitize\Sanitizer` class name, so a
single PHP process can only ever hold one of them. Rather than fight that with
subprocesses or class aliasing, the app leans into it: a request handles one
version, and switching versions in the UI runs a fresh request.

## Keeping up with releases

Versions arrive through git, never by the web server installing things on its
own. Two halves:

**In CI** — `.github/workflows/sync-versions.yml` runs `bin/sync-versions.php
--lock` every six hours. That asks Packagist what has been released and, for
anything new, writes `versions/<version>/composer.json`, its `composer.lock`,
and an entry in `versions/index.json`. It downloads no code. If those files
changed, it commits and pushes them.

**On deploy** — pushing to `main` triggers the Forge deploy, which runs
`bin/sync-versions.php --install`. That runs `composer install` for any version
whose `vendor/` is missing, then starts each version in its own process to check
it actually runs on this host's PHP, and writes `storage/versions.json`.

So a new release becomes: Packagist → CI commit → Forge deploy → live. The lock
files sit in a commit you can read before any of it reaches the server.

### Triggering it the moment you tag a release

The six-hourly schedule will pick a release up on its own. To make it immediate,
add a step to the release workflow in the `svg-sanitizer` repo:

```yaml
- name: Nudge the playground
  run: |
    curl -X POST \
      -H "Authorization: Bearer ${{ secrets.PLAYGROUND_DISPATCH_TOKEN }}" \
      -H "Accept: application/vnd.github+json" \
      https://api.github.com/repos/<you>/<this-repo>/dispatches \
      -d '{"event_type":"svg-sanitize-release"}'
```

`PLAYGROUND_DISPATCH_TOKEN` needs a fine-grained PAT with `contents: write` on
this repository.

## Forge setup

1. Create the site with **web directory** `/public`.
2. Point it at this repository and enable **Quick Deploy**.
3. Deployment script:

   ```bash
   cd /home/forge/svg.enshrined.co.uk
   git pull origin $FORGE_SITE_BRANCH
   $FORGE_PHP bin/sync-versions.php --install
   ( flock -w 10 9 || exit 1; echo 'Restarting FPM'; sudo -S service $FORGE_PHP_FPM reload ) 9>/tmp/fpmlock
   ```

   `deploy.sh` in this repo does the same thing if you would rather call that.
4. PHP 8.1 or newer. The first deploy installs every released version at once —
   a minute or two. If it ever gets cut short, add `--limit=15` and deploy again
   until it settles; each run picks up where the last one stopped.

Nothing needs a database, a queue, or a writable directory beyond `storage/`.

## Running it locally

```bash
php bin/sync-versions.php     # lock and install every released version
composer serve                # or: php -S 127.0.0.1:8080 -t public
```

With [Herd][herd] or Valet, the `public/` directory is picked up automatically —
just visit the site's `.test` hostname.

[herd]: https://herd.laravel.com

## bin/sync-versions.php

| Flag | What it does |
| --- | --- |
| `--lock` | Ask Packagist for releases; write `composer.json`/`composer.lock` for new ones. Downloads nothing. |
| `--install` | Build `vendor/` from the committed locks, probe each version, write the manifest. |
| *(neither)* | Both, in order — the cron-friendly mode if you would rather not use CI. |
| `--only=1.0.0,0.22.0` | Restrict to these versions. |
| `--limit=5` | Install (or lock) at most this many new versions in one run, so a run that gets cut short resumes on the next one. |
| `--reinstall` | Delete and rebuild matched versions. |
| `--prune` | Remove local versions Packagist no longer lists. |
| `--dry-run` | Say what would happen, change nothing. |
| `--quiet` | Warnings and errors only. |

A version that installs but cannot run on the host PHP is recorded as unusable
with the reason, and the UI greys it out rather than pretending it works.

## Notes on safety

This is a page that runs hostile SVG on purpose, so a few things are deliberate:

- **Previews are contained.** Both the input and the output render through
  `preview.php`, whose policy begins with CSP `sandbox` — an opaque origin with
  scripts off, enforced however the document is reached — plus `default-src
  'none'` so nothing in a preview can make a request. The iframe carries its own
  `sandbox` attribute on top of that.

  It is served from a real URL rather than a `blob:` or `srcdoc:` frame on
  purpose: those inherit the embedding page's CSP, which would strip the very
  styles the SVG is meant to be drawn with and make the preview a lie.
- **There is no upload.** You paste; the SVG is posted when you hit Sanitize or
  open a preview, and is never written to disk.
- **The DoS levers are not exposed.** `setAllowHugeFiles`, `useThreshold` and
  `setUseNestingLimit` are fixed at safe values server-side and cannot be set
  from the browser.
- **Input is capped** at 2 MB, and version strings are validated before they go
  anywhere near a filesystem path.

## Sharing a repro

The URL takes `?sample=<slug>`, `?v=<version>` and `?run=1`, so you can hand
someone a link that reproduces exactly what you were looking at:

```
https://svg.enshrined.co.uk/?sample=script-injection&v=0.15.4&run=1
```
