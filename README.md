# fp-mu-plugin

**FrankenPress must-use plugin** — platform-essential WordPress glue for the
FrankenPress stack. Four components, all platform-housekeeping:

**Documentation:** <https://docs.frankenpress.com/components/fp-mu-plugin>

| Component | What it does |
|---|---|
| **S3UploadsBootstrap** | Configures [`humanmade/s3-uploads`](https://github.com/humanmade/S3-Uploads) from `FP_S3_*` env vars and **refuses media uploads** when S3 isn't fully configured (rather than silently falling back to ephemeral local disk in a containerized deploy). |
| **SouinInvalidator** | `DEL`s Souin's Redis cache entries directly on `save_post`, `clean_post_cache`, `switch_theme`, etc. — Souin's documented HTTP invalidation APIs are broken in cache-handler v0.16.0 (see [`fp-runtime/PHASE-0.md`](https://github.com/EightOEight/fp-runtime/blob/main/PHASE-0.md)). |
| **SiteHealth** | Suppresses Site Health tests whose failure is intentional under the immutable-image lockdown (`background_updates`, FS-write probes, `plugin_theme_auto_updates`), adds a passing FrankenPress-branded test that explains why, and adds an SMTP-reachability test when SMTPMailer is configured. |
| **SMTPMailer** | Wires the global PHPMailer to send via SMTP from `FP_SMTP_*` env vars. The fp-runtime image ships no MTA, so without this every `wp_mail()` call fails silently. Transport-agnostic (Postmark, SendGrid, Mailgun, AWS SES, in-cluster relay). Opt-in: no-op when `FP_SMTP_HOST` is unset. |

That's the entire mu-plugin. Anything else (object cache, multisite URL fixing,
WooCommerce log handlers, Prometheus metrics) is **optional** by the FrankenPress
baseline definition — sites that need it install it themselves. Keeping the
must-use surface tiny means less chance of platform-level regressions, easier
audit, and a cleaner contract with downstream sites.

## Status

🚧 Phase 2 in progress — first release will be `v0.1.0`.

## Install

Composer-installed into a Bedrock-layout site:

```bash
composer require eightoeight/fp-mu-plugin
```

This pulls `humanmade/s3-uploads` as a transitive dependency and lands the
plugin at `web/app/mu-plugins/fp-mu-plugin/`. The bootstrapper file
`fp-mu-plugin.php` needs to live in the mu-plugins root (one level up) —
`humanmade/s3-uploads` and other mu-plugin packages handle this with a
small loader file; we follow the same convention.

## Configuration

All env vars are optional unless flagged required.

### S3UploadsBootstrap

| Var | Default | Purpose |
|---|---|---|
| `FP_S3_BUCKET` | (required) | S3 bucket name |
| `FP_S3_KEY` | (required) | IAM access key id |
| `FP_S3_SECRET` | (required) | IAM secret access key |
| `FP_S3_REGION` | `us-east-1` | S3 region |
| `FP_S3_BUCKET_URL` | (optional) | Public CDN URL for served media (e.g. `https://cdn.example.com`). Auto-sets `WP_CONTENT_URL` if undefined. |
| `FP_S3_ENDPOINT` | (optional) | Custom S3-compatible endpoint (MinIO, R2, GCS XML API). Empty for AWS S3. |
| `FP_S3_OBJECT_ACL` | (empty — no ACL header sent) | Optional S3 object ACL (`public-read`, `private`, `authenticated-read`). Leave unset for buckets with **Object Ownership = "Bucket owner enforced"** (the AWS default for new buckets since April 2023, ACLs disabled). Sending an ACL on such a bucket aborts the upload and leaves a **0-byte object** behind. Set to `public-read` to opt in for ACL-enabled buckets. |
| `FP_S3_DISABLED` | `false` | Set truthy (`1`, `true`, `yes`, `on`) to disable S3 entirely. **Local dev only — never in production**, because container disks are ephemeral and inconsistent across replicas. |

If any required var is missing, the bootstrap registers a
`wp_handle_upload_prefilter` filter that **rejects every upload** with a
clear error message. We deliberately don't fall back to local disk — in a
Kubernetes deploy that means uploads vanish on pod restart and don't replicate
across replicas, which is far worse than a hard failure.

### SouinInvalidator

| Var | Default | Purpose |
|---|---|---|
| `FP_SOUIN_REDIS_HOST` | `redis` | Redis hostname (matches `fp-runtime`'s docker-compose service) |
| `FP_SOUIN_REDIS_PORT` | `6379` | Redis port |
| `FP_SOUIN_REDIS_PASSWORD` | (empty) | Redis AUTH password |
| `FP_SOUIN_REDIS_DB` | `0` | Logical database |
| `FP_SOUIN_REDIS_TIMEOUT` | `1.0` | Connect timeout (seconds) |
| `FP_SOUIN_DISABLED` | `false` | Truthy to no-op the invalidator (cache then expires only by TTL) |

The invalidator covers every WP write event whose output is templated
into cached pages. The list is grouped by blast radius:

| Category | Hooks | Action |
|---|---|---|
| Post save / delete | `save_post`, `wp_trash_post`, `before_delete_post`, `deleted_post`, `clean_post_cache` | DEL post URL + tag + home |
| Global-impact post types | `save_post` for `wp_navigation` / `wp_block` / `wp_template` / `wp_template_part` / `wp_global_styles` | `invalidate_all` |
| Comments | `comment_post`, `transition_comment_status` | DEL parent post |
| Term lifecycle | `created_term`, `edited_term`, `delete_term` | `invalidate_all` (archive + every listing) |
| User lifecycle | `profile_update`, `user_register`, `deleted_user` | DEL author archive + home |
| Site-wide | `switch_theme`, `permalink_structure_changed`, `wp_update_nav_menu`, `customize_save_after`, `activated_plugin`, `deactivated_plugin` | `invalidate_all` |
| Options | `updated_option` for `blogname`, `home`, `siteurl`, `permalink_structure`, `sidebars_widgets`, anything matching `widget_*` | `invalidate_all` |

Heuristic: bounded change → invalidate_url (precise); unbounded change
(anything templated into every page) → invalidate_all (blunt but
correct). The TTL expiry is the safety net for anything missed.

If `ext-redis` isn't loaded, or the connection fails, the invalidator
becomes a silent no-op. **Errors are logged but never raised** — a broken
cache layer must not break WP itself.

### SMTPMailer

| Var | Default | Purpose |
|---|---|---|
| `FP_SMTP_HOST` | (unset) | SMTP server hostname (e.g. `smtp.postmarkapp.com`). Component is a no-op when unset. |
| `FP_SMTP_PORT` | `587` | TCP port |
| `FP_SMTP_ENCRYPTION` | `tls` | `tls` (STARTTLS), `ssl` (implicit TLS), `none` (local dev only) |
| `FP_SMTP_USERNAME` | (unset) | SMTP auth username |
| `FP_SMTP_PASSWORD` | (unset) | SMTP auth password |
| `FP_SMTP_FROM_EMAIL` | (WP `admin_email`) | `wp_mail_from` filter target |
| `FP_SMTP_FROM_NAME` | (WP `blogname`) | `wp_mail_from_name` filter target |
| `FP_SMTP_DISABLED` | `false` | Truthy to no-op the bootstrap. **Local dev only** — the chart never sets this; chart-level `smtp.enabled: false` covers the same need by simply not injecting the env. |

When `FP_SMTP_HOST` is unset, SMTPMailer is a silent no-op and `wp_mail()`
falls through to PHP's `mail()` (which fails on the fp-runtime image since
no MTA is shipped — that's the intentional default state for sites that
haven't opted into SMTP yet). When the host is set but unreachable / auth
fails, `wp_mail()` returns `false` and the failure is logged; we never retry,
queue, or fall back to `mail()`. The "is my SMTP actually working" check is
in the `SiteHealth` component (only added when `FP_SMTP_HOST` is set).

**Plugin-coexistence note.** A site that composer-installs a competing
SMTP plugin (WP Mail SMTP, FluentSMTP, the Postmark official plugin) will
override SMTPMailer's config — those run as regular plugins after must-use
plugins and re-hook `phpmailer_init` at the same priority, so last-writer
wins. That's the correct behaviour: the user opted into the plugin
explicitly.

## Souin Redis key shape (for reference)

The mu-plugin assumes the following Redis keys, verified empirically against
cache-handler v0.16.0:

```
GET-<scheme>-<host>-<path>     cached response body
IDX_GET-<scheme>-<host>-<path> index entry pointing at the body
SURROGATE_<tag>                Redis SET of cache keys associated with a tag
```

`invalidate_url($url)` DELs the body + index pair for **both `http` and
`https` scheme variants** of the URL. Souin keys with whatever scheme
it observed for the request (which depends on whether Caddy is
configured to trust an upstream proxy's `X-Forwarded-Proto` header),
while WordPress's `home_url()` returns the canonical scheme. The two
can drift in proxy chains (CDN → LB → Caddy), so dual-scheme DEL
removes a class of silent invalidation misses.

`invalidate_tag($tag)`:
1. `SMEMBERS SURROGATE_<tag>` to enumerate cached entries under that tag
2. Pipeline `DEL` each member's body + index keys
3. `DEL` the SURROGATE_ index itself

`invalidate_all()` SCANs `GET-*`, `IDX_*`, `SURROGATE_*` patterns and DELs
each batch — used for theme switches and permalink structure changes that
affect every cached page.

## Development

```bash
composer install
composer ci         # phpcs + phpstan + phpunit + composer audit
composer test       # just phpunit
composer lint       # just phpcs
composer lint:fix   # phpcbf
composer stan       # just phpstan
```

The unit tests use [Brain Monkey](https://github.com/Brain-WP/BrainMonkey)
to stub WordPress functions and Mockery to mock the Redis client — no real
Redis or WP install needed.

## Companion repos

| Repo | Purpose |
|---|---|
| [`fp-runtime`](https://github.com/EightOEight/fp-runtime) | Base container image (Caddy + FrankenPHP + Souin) |
| [`fp-mu-plugin`](https://github.com/EightOEight/fp-mu-plugin) (this repo) | Must-use plugin (this repo) |
| [`fp-site-template`](https://github.com/EightOEight/fp-site-template) | GitHub template for new sites — Bedrock-layout WordPress with S3 uploads |
| [`fp-charts`](https://github.com/EightOEight/fp-charts) | Helm chart `fp-site` for Kubernetes deployment |
