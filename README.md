# IndexNowPing

**Tell Bing, Yandex & Co. the moment a page changes — automatically, from inside ProcessWire.**

IndexNowPing is a ProcessWire module that submits your new, changed, moved and deleted
URLs to [IndexNow](https://www.indexnow.org) — the shared ping protocol used by Bing,
Yandex, Seznam, Naver and Yep. No account, no API key from any search engine: one key
file in your webroot is the whole signup. Instead of waiting days for the next crawl,
participating engines get a nudge within seconds of you hitting *Save*.

Built for production use: it survives importers that save a page field-by-field, keeps
development installs structurally silent, and never sends a URL that isn't yours.

## Features

- **Zero-signup pings** — works with the free IndexNow protocol; no engine accounts needed
- **Four page events**: `Pages::saved`, `deleted`, `moved` (old *and* new URL) and `trashed`
  (old URL, captured before the path changes)
- **Request coalescing** — importers and migration tools that call `$pages->saveField()`
  per field fire `Pages::saved` once *per field*. IndexNowPing buffers per request and
  submits once at shutdown: one POST instead of hundreds (no 429 "potential spam")
- **Kill-switch precedence** — `$config->indexNow = false;` in any config file disables
  every submission instantly and beats all other settings. Perfect for dev/staging
  configs that are never loaded in production
- **Config screen with health panel** — key, host, endpoint and template whitelist
  (ASM select) in *Setup → Modules → IndexNowPing*, plus live status: effective
  configuration, key-file check and the last log entries
- **Key file managed for you** — paste (or generate) a key, the module writes and rotates
  `/<key>.txt` in your webroot and warns in the config screen when file and setting drift apart
- **URL hardening** — every URL is normalized to your canonical `https://host/` origin
  (ProcessWire's `httpUrl()` returns `http://` in CLI contexts) and foreign URLs are dropped
- **10,000-URL batches** — IndexNow's per-request limit, chunked for you
- **JSON-line logging** — one line per request in `site/assets/logs/indexnow.txt`:
  context, count, HTTP status, response body sample, URL sample

## Requirements

- ProcessWire 3.x
- PHP >= 8.0

## Installation

1. Copy the `IndexNowPing/` folder (containing `IndexNowPing.module.php`) into
   `site/modules/`.
2. In the admin: **Modules → Site → Refresh** (or click *Check for new modules*), then
   **Install** next to *IndexNow Ping*.
3. Open the module settings and:
   - pick the **templates** whose pages should be submitted,
   - set your **canonical host** (e.g. `www.example.com`, with `www.` if that's your
     canonical form — leave empty to use `$config->httpHosts[0]`),
   - generate a key: `openssl rand -hex 16` and paste it into **IndexNow key**.
     The module writes the required `/<key>.txt` key file into your webroot automatically.
4. Save any page with a whitelisted template and watch
   `site/assets/logs/indexnow.txt` (also shown in the module's config screen).

Have an existing site? Run the bundled one-time stock submission once (see below).

## One-time full-stock submission

The module hooks cover *changes* from now on. To hand engines your existing pages once,
use `extras/submit-all.php` — copy it into your ProcessWire root and run:

```bash
php submit-all.php --dry-run   # preview: URL count, hosts, samples — sends nothing
php submit-all.php --yes       # actual submission (10,000 URLs max. per request)
```

It reads the template whitelist, host and key from the module settings, skips hidden
and `noindex` pages, and uses the same code path as the hooks.

## Configuration precedence

Effective values are resolved per field, first match wins:

| Stage | Source | Purpose |
| ----- | ------ | ------- |
| 1 | `$config->indexNow === false` (exact boolean) | **Kill-switch** — beats everything, everywhere |
| 2 | Module settings (config screen, stored in DB) | Day-to-day configuration |
| 3 | `$config->indexNow` array in `site/config.php` | Field defaults, e.g. for repeatable setups |
| 4 | Built-in defaults | Endpoint `https://api.indexnow.org/indexnow`, `httpHosts[0]` |

Empty module fields fall through to stage 3/4 — the config screen shows the effective
value for every field, so there is only ever one truth.

### Keeping development quiet

Module settings live in the database and therefore travel with database dumps into your
dev environment. One line in your dev config keeps it structurally silent:

```php
// site/config-dev.php (or whatever your dev config is)
$config->indexNow = false;
```

With the kill-switch present, the module registers no hooks, submits nothing, and the
config screen shows `KILL-SWITCHED` — regardless of what the database says.

## What IndexNow does (and does not) do

IndexNow asks an engine to crawl a URL sooner. It does **not** guarantee indexing — the
engine still decides what lands in the index. Google does not participate in IndexNow;
for Google, keep using your sitemap and Search Console. Expect effects at Bing and the
other participating engines: faster pickup of new pages, faster refresh of changed ones.

## Logging & troubleshooting

Every submission writes one JSON line to `site/assets/logs/indexnow.txt` (disable via
config screen). The HTTP status tells you everything:

| Status | Meaning |
| ------ | ------- |
| 200 / 202 | Accepted (202 = key still being verified asynchronously) |
| 400 | Malformed request — check the logged body |
| 403 | Key invalid or key file missing/empty — the config screen's key-file check finds this |
| 422 | URLs don't match the declared host — check the canonical host setting |
| 429 | Too many requests — stop; the coalescing usually prevents this |

## License

[MIT](LICENSE) — use it in any project, commercial included.
