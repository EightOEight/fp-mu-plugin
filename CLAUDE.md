# CLAUDE.md — fp-mu-plugin

Guidance for Claude Code (and other AI agents) when working in this repo.

## What this repo is

The **must-use plugin** for the FrankenPress stack. **Two components only**,
both runtime-mandatory; anything else is **optional** and lives in a
regular plugin (or a fork of this).

| Component | Job |
|---|---|
| `FrankenPress\S3UploadsBootstrap` | Configures `humanmade/s3-uploads` from `FP_S3_*` env vars; **refuses uploads** if S3 isn't fully wired (no silent local-disk fallback). |
| `FrankenPress\SouinInvalidator` | Connects directly to Redis and `DEL`s Souin's HTTP cache entries on `save_post`, `clean_post_cache`, comment status changes, theme switch, permalink change, global-option change. Bypasses cache-handler v0.16.0's broken HTTP invalidation APIs. |

Composer name: **`eightoeight/fp-mu-plugin`** (PSR-4 namespace `FrankenPress\\`).
Latest: `v0.1.1`.

Public docs: **<https://docs.frankenpress.com/components/fp-mu-plugin>**

## File layout

- `fp-mu-plugin.php` — root mu-plugin loader. WordPress only auto-loads PHP files at the root of `mu-plugins/`, not in subdirs. This file is what the consumer site's `roots/bedrock-autoloader` discovers.
- `src/MuPlugin.php` — wires the two components into WordPress hooks.
- `src/S3UploadsBootstrap.php` — env → constants → `s3_uploads_s3_client_params` filter → require_once humanmade/s3-uploads. Refuses uploads via `wp_handle_upload_prefilter` when required env vars are missing.
- `src/SouinInvalidator.php` — Redis client wrapper + WordPress hook callbacks. Computes Souin cache keys (`GET-<scheme>-<host>-<path>`) and DELs them.
- `tests/` — PHPUnit unit tests with [Brain Monkey](https://github.com/Brain-WP/BrainMonkey) (WP function stubs) + Mockery (Redis client mocks). No real Redis or WP install needed.
- `composer.json` — pulls `humanmade/s3-uploads ^3.0` as a transitive dep so consumer sites get S3 support without an extra `composer require`.

## Conventions

- **Two components is the contract.** Adding a third (URL fixer, object cache, metrics, WC log handler, etc.) requires explicit user approval. Sites that need those install them as regular plugins.
- **Errors are logged, never raised.** A broken Redis or missing s3-uploads makes the component a silent no-op (with `error_log`). The mu-plugin must never break WordPress request handling.
- **Direct Redis DEL is the canonical invalidation path.** Souin's `PURGE`, POST-CRUD, and `/api.souin/*` admin endpoints are broken in cache-handler v0.16.0 — see `fp-runtime/PHASE-0.md`. Don't add code that depends on them coming back.
- **Hard-coded refusal beats silent fallback.** If `FP_S3_BUCKET`/`KEY`/`SECRET` are missing, the bootstrap registers `wp_handle_upload_prefilter` to **reject every upload**. In a containerized environment, silently writing to local disk is far worse than a hard fail.
- **PSR-4 namespace is `FrankenPress\\`.** Don't introduce sub-namespaces deeper than `FrankenPress\Integrations\<X>` unless there's a reason.
- **Strict types declared in every file** (`declare(strict_types=1);`).

## Don'ts

- **Don't add a third component without explicit user approval.** The slim baseline is a deliberate scope decision.
- **Don't reintroduce QuerySplit, CDNOffloader, ContentFilter, NginxHelperActivator, MediaStorage, or BlobStore code from the old `wp-mu-plugin`.** They were intentionally dropped.
- **Don't add MetricsCollector or WooCommerce log handlers here.** Those are optional integrations sites install themselves.
- **Don't use `humanmade/s3-uploads`'s WP-CLI interface inline.** The bootstrap require_onces the plugin's main file but doesn't shell out — keep it that way for predictable load order.
- **Don't change the Souin Redis key shape (`GET-<scheme>-<host>-<path>`)** without coordinating with [`fp-runtime`](https://github.com/EightOEight/fp-runtime). The runtime's Souin instance and this plugin's `SouinInvalidator` must agree on the cache key layout.
- **Don't add a real Redis or WordPress install to the test suite.** Tests use Brain Monkey + Mockery. Integration tests against a live stack live in [`fp-charts`](https://github.com/EightOEight/fp-charts)' kind cluster smoke test.

## Local testing

```bash
composer install
composer test       # phpunit (Brain Monkey + Mockery)
composer lint       # phpcs (WordPress-Core ruleset)
composer stan       # phpstan level 6
composer ci         # all four checks (lint + stan + test + audit)
```

## When you change behavior

Keep these in sync:

1. The hook list in `src/SouinInvalidator.php` (or wherever else hooks live)
2. README's component table + env-var table
3. `https://docs.frankenpress.com/components/fp-mu-plugin`
4. `https://docs.frankenpress.com/operations/configuration` (env vars)

## Companion repos

| Repo | Purpose |
|---|---|
| [`fp-runtime`](https://github.com/EightOEight/fp-runtime) | Base container image (bakes this plugin in) |
| [`fp-mu-plugin`](https://github.com/EightOEight/fp-mu-plugin) (this repo) | Must-use plugin |
| [`fp-site-template`](https://github.com/EightOEight/fp-site-template) | GitHub template for new sites (composer-requires this plugin) |
| [`fp-charts`](https://github.com/EightOEight/fp-charts) | Helm chart `fp-site` |
| [`docs`](https://github.com/EightOEight/docs) | Mintlify docs site |
