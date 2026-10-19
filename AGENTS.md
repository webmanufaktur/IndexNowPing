# AGENTS.md — IndexNowPing development guide

For humans and coding agents working *on* this module (using it? read the
[README](README.md) instead). Everything here is context you would otherwise
have to re-derive from ProcessWire internals.

## What this is

A single-file ProcessWire 3.x module (`IndexNowPing.module.php`, ~700 lines,
PHP >= 8.0) plus one companion script (`extras/submit-all.php`). No build
step, no dependencies outside ProcessWire core. It is used in production on a
live site — treat `master` as stable.

## Repo layout

```
IndexNowPing.module.php   the module (single file, that's deliberate)
extras/submit-all.php     one-time full-stock submission CLI (copy into a PW site to run)
README.md                 user-facing docs
AGENTS.md                 this file
LICENSE                   MIT
```

## Conventions

- ProcessWire coding style, 2-space indent, `namespace ProcessWire`.
- One module file on purpose: PW site-modules of this size are easier to
  install/copy/review as a single file. Don't split into classes unless the
  file grows past ~1,000 lines.
- No tests in-repo; the manual test recipe below is the verification gate.
  Run `php -l` on every change at minimum.
- Versioning: bump `getModuleInfo()['version']` on every behavioral change,
  tag `vX.Y.Z`, update the changelog section in the release notes on GitHub.

## Architecture invariants (do not break these)

1. **Kill-switch supremacy.** `$config->indexNow === false` must disable every
   outbound request path — hooks, `submit()`, batch. It is the dev-safety story:
   module settings live in the DB and travel with dumps into environments that
   must never ping. `resolveConfig()` short-circuits on it before anything else.
2. **HTTPS-only, own-origin-only.** `normalizeUrl()` forces the canonical
   `https://<host>/` origin (path preserved) and drops everything else. This is
   load-bearing: PW's `httpUrl()` returns `http://` in CLI boots, and a naive
   own-origin filter silently drops every CLI/importer save (that was a real
   bug in the first implementation).
3. **Per-request coalescing.** Hooks only buffer; `flush()` submits once per
   request via `register_shutdown_function()`. `$pages->saveField()` fires
   `Pages::saved` once per field — without coalescing, an importer updating
   6 fields on 300 pages sends 1,800 POSTs and earns a 429.
4. **`init()` is cheap.** The module autoloads on *every* request, frontend
   included. No DB queries, no template lookups, no remote calls in `init()`;
   `resolveConfig()` must stay pure array merging (it does template-name
   filtering without touching `$templates`).
5. **No indexnow protocol magic.** Max 10,000 URLs per POST, `keyLocation`
   points at the webroot key file, status codes are logged verbatim. The
   protocol spec: https://www.indexnow.org/documentation

## ProcessWire quirks encoded in this codebase

Learned the hard way; keep this list when touching the related code:

- **`--at-stdin` CLI tools don't boot `ready.php`** — hooks registered there
  never fire in such contexts. IndexNowPing registers its hooks in `init()`,
  which autoloaded modules run in every boot mode that loads modules.
- **CLI `httpUrl()` yields `http://`** and can resolve a surprising host —
  hence the normalization in `normalizeUrl()` and the raw-sample diagnostic
  in `extras/submit-all.php`.
- **`FieldtypeOptions` truthiness is a trap**: an unset option field is an
  empty-but-truthy object, and the formatted value's `->title` can be NULL in
  the web context. Read option fields via `getUnformatted()` (see
  `isNoindex()`).
- **FileCompiler** keeps compiled copies under `site/assets/cache/FileCompiler/`
  and recompiles on mtime — after editing the module file, a modules refresh or
  any page load picks changes up; don't debug against stale compiled copies.
- **`WireHttp::post($url, $data, array $options)`** accepts a raw JSON string
  as `$data`; `headers` is an associative array, `timeout` is seconds, status
  via `getHttpCode()`, failure via `getError()`.

## Manual test recipe (the release gate)

No CI — this is the checklist. Needs a ProcessWire install (e.g. DDEV) with at
least one content template (say `post`) and the module installed and enabled.

1. **Kill-switch:** add `$config->indexNow = false;` to the dev config, save a
   whitelisted page (web *or* CLI full boot) → no new line in
   `site/assets/logs/indexnow.txt`. Config screen shows `KILL-SWITCHED`.
2. **Coalescing + full path:** remove the kill-switch, point the module's
   endpoint at `https://indexnow-sink.invalid/` (guaranteed DNS failure —
   proves the request path without external traffic), then in ONE CLI script
   (full `include 'index.php';` boot!): create a published page, call
   `saveField()` twice on it, create a second page, trash it, delete the
   first. Expect **exactly one** new log line: `context:"hook"`, `count:2`
   (both URLs, deduped), `status:0`, and the URLs must be `https://…`.
3. **Batch:** `php extras/submit-all.php --dry-run` (plausible count, hosts,
   samples) and `--yes` under kill-switch → `skipped: kill-switch`.
4. **Key file:** set a key in the config screen → `/<key>.txt` exists in the
   webroot, content exactly the key, no trailing newline (`od -c`). Change the
   key → old file gone. Config screen health matches.
5. **Frontend smoke:** home + one content page render 200 with the module
   enabled; `site/assets/logs/errors.txt` stays empty.
6. Only then: version bump, tag, push.

## Releasing / publishing

- `master` is the release branch. Tag signed-off releases `vX.Y.Z`.
- For the ProcessWire modules directory: submit via
  https://processwire.com/modules/submit/ pointing at this repo; the module's
  `getModuleInfo()` (`author`, `href`, `summary`) is the directory metadata.
- Keep the in-repo production copy (site that shall not be named here) in
  sync: port behavioral changes both ways, the site runs the same file.

## License note for contributors

MIT (see [LICENSE](LICENSE)). By contributing you agree your contribution is
licensed under it as well.
