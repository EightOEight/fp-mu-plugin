# mu-plugin

**FrankenPress must-use plugin** — platform-essential WordPress glue for the
FrankenPress stack. Four request-path components + one off-request-path
CLI surface, all platform-housekeeping:

**Documentation:** <https://docs.frankenpress.com/components/mu-plugin>

| Component | What it does |
|---|---|
| **S3UploadsBootstrap** | Configures [`humanmade/s3-uploads`](https://github.com/humanmade/S3-Uploads) from `FP_S3_*` env vars and **refuses media uploads** when S3 isn't fully configured (rather than silently falling back to ephemeral local disk in a containerized deploy). |
| **SouinInvalidator** | `DEL`s Souin's Redis cache entries directly on `save_post`, `clean_post_cache`, `switch_theme`, etc. — Souin's documented HTTP invalidation APIs are broken in cache-handler v0.16.0 (see [`runtime/PHASE-0.md`](https://github.com/frankenpress/runtime/blob/main/PHASE-0.md)). |
| **SiteHealth** | Suppresses Site Health tests whose failure is intentional under the immutable-image lockdown (`background_updates`, FS-write probes, `plugin_theme_auto_updates`), adds a passing FrankenPress-branded test that explains why, and adds an SMTP-reachability test when SMTPMailer is configured. |
| **SMTPMailer** | Wires the global PHPMailer to send via SMTP from `FP_SMTP_*` env vars. The runtime image ships no MTA, so without this every `wp_mail()` call fails silently. Transport-agnostic (Postmark, SendGrid, Mailgun, AWS SES, in-cluster relay). Opt-in: no-op when `FP_SMTP_HOST` is unset. |
| **Cli\\Command** *(WP-CLI only)* | Registers `wp fp snapshot` + `wp fp apply` subcommands. **Adapter-scoped, WXR-based, additive.** Snapshots capture only what the active adapter declares is its blast radius (the bundled `Fse` adapter covers FSE block-theme design state — templates, template parts, global styles, navigation, attachments, pages, posts, site-identity options). User-generated content (orders, comments, accounts) is never in scope and never touched. Apply uses WP's native WXR importer + scoped `update_option`. Schema: `fp.snapshot/v3`. Loads only under `WP_CLI` — zero overhead on web requests. |

That's the entire mu-plugin. Anything else (object cache, multisite URL fixing,
WooCommerce log handlers, Prometheus metrics) is **optional** by the FrankenPress
baseline definition — sites that need it install it themselves. Keeping the
must-use surface tiny means less chance of platform-level regressions, easier
audit, and a cleaner contract with downstream sites.

## `wp fp` CLI subcommands

The FrankenPress promotion CLI lives as WP-CLI subcommands. They run
inside the WP environment (where serialised options, themes, and
adapters are loaded) and emit / consume a portable snapshot bundle
that's committed into the site repo at `web/imports/<slug>/`.

```bash
# Capture local state into web/imports/<slug>/
wp fp snapshot --slug=homepage-rev2 --note="Block-pattern refresh + accent tweak"
# → web/imports/homepage-rev2/{manifest.yaml,manifest.json,content.xml.gz,options.json,uploads-manifest.txt}

# Designer commits the directory + opens a site-repo PR. The site image
# is rebuilt with web/imports/ baked in; the chart's install Job iterates
# the dir on every reconcile and runs `wp fp apply` per snapshot.

# Apply a snapshot to the current site (used by the chart's install Job)
wp fp apply --snapshot-dir=/app/web/imports/homepage-rev2
```

### Safety properties

- **Adapter-scoped.** The active adapter declares what rows it
  considers in-scope for a snapshot (`post_types`, `option_keys`,
  `theme_mods_for`). The capture path honors that scope exactly —
  nothing else makes it into the snapshot. The bundled `Fse` adapter
  scope covers FSE block-theme design surface (templates, template
  parts, global styles, navigation, attachments, pages, posts) +
  curated site-identity option keys (`blogname`, `show_on_front`,
  `page_on_front`, etc.). WooCommerce orders / user accounts /
  comments are **never** in any adapter's scope and thus can never
  be carried by `fp` snapshot in any direction.
- **WXR-based content + JSON options sidecar.** Content (posts, terms,
  menus) ships as WXR — the format WP's native importer ingests
  additively (only INSERTs, dedups terms by slug, remaps post IDs on
  collision). Scoped wp_options ride along as a JSON sidecar.
- **Additive apply.** `wp fp apply` runs `wp import` (additive) plus
  `update_option` per sidecar entry. There is **no DROP, no DELETE, no
  TRUNCATE** anywhere in the apply path.

### Idempotency

`apply` stamps `fp_snapshot_applied_ref` and `fp_snapshot_applied_sha256`
options after success; subsequent invocations with the same snapshot
short-circuit cleanly. Schema: `fp.snapshot/v3`.

## Install

Composer-installed into a Bedrock-layout site:

```bash
composer require frankenpress/mu-plugin
```

This pulls `humanmade/s3-uploads` as a transitive dependency and lands the
plugin at `web/app/mu-plugins/mu-plugin/`. The bootstrapper file
`mu-plugin.php` needs to live in the mu-plugins root (one level up) —
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
| `FP_S3_DISABLED` | auto: enabled in-cluster, disabled out-of-cluster | Tri-state. Truthy (`1`/`true`/`yes`/`on`) skips the bootstrap. Falsy (`0`/`false`/`no`/`off`) forces it on (e.g. to exercise the S3 path locally against MinIO). Unset/empty falls back to the default, which gates on `KUBERNETES_SERVICE_HOST` — enabled in-cluster, disabled out-of-cluster so admin install flows that `unzip_file()` the upload dir don't hit the `s3://` stream wrapper. **Production always sees the var unset and `KUBERNETES_SERVICE_HOST` set, so S3 stays on by default in cluster.** |

If any required var is missing, the bootstrap registers a
`wp_handle_upload_prefilter` filter that **rejects every upload** with a
clear error message. We deliberately don't fall back to local disk — in a
Kubernetes deploy that means uploads vanish on pod restart and don't replicate
across replicas, which is far worse than a hard failure.

### SouinInvalidator

| Var | Default | Purpose |
|---|---|---|
| `FP_SOUIN_REDIS_HOST` | `redis` | Redis hostname (matches `runtime`'s docker-compose service) |
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
falls through to PHP's `mail()` (which fails on the runtime image since
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
| [`runtime`](https://github.com/frankenpress/runtime) | Base container image (Caddy + FrankenPHP + Souin) |
| [`mu-plugin`](https://github.com/frankenpress/mu-plugin) (this repo) | Must-use plugin (this repo) |
| [`site-template`](https://github.com/frankenpress/site-template) | GitHub template for new sites — Bedrock-layout WordPress with S3 uploads |
| [`charts`](https://github.com/frankenpress/charts) | Helm chart `site` for Kubernetes deployment |
