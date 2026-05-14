# Cache architecture and invalidation

**Status:** done (reference)
**Created:** 2026-05-06
**Owner:** unassigned

## Context

FrankenPress runs an HTTP cache (Souin, via the
[`caddyserver/cache-handler`](https://github.com/caddyserver/cache-handler)
module) in front of FrankenPHP's WordPress execution. Anonymous public
GETs hit the cache; authenticated and admin traffic bypasses it
entirely. Cache invalidation is performed by the `SouinInvalidator`
component of `mu-plugin`, which writes directly to Redis when
WordPress fires lifecycle hooks (post saves, term changes, menu
updates, etc.).

This sounds simple. It is not, in practice — over a 24-hour window in
May 2026 we hit five distinct bugs that all manifested as some flavour
of "I changed something but the public site shows the old version" or
"I logged out but my session content is still showing." The fixes
landed across `runtime`, `mu-plugin`, and (transiently) the
consuming `eoe` site. This doc captures the architecture, the
deliberate non-obvious decisions, the limitations we accepted, and the
bug chronology — so the next person to debug something cache-shaped on
this stack starts with the full picture rather than re-deriving it.

## Architectural overview

```text
Browser  ─── HTTPS ───►  CDN/LB (TLS termination)  ─── HTTP ───►  Caddy (FrankenPHP binary)
                                                                    │
                                                                    │  matchers run BEFORE cache
                                                                    │  ┌──────────────────────────┐
                                                                    ├─►│ @auth_or_state_cookie?   │── yes ──►  PHP (no cache layer)
                                                                    │  └──────────────────────────┘
                                                                    │  no
                                                                    ▼
                                                                  Souin
                                                          (cache-handler module)
                                                                    │
                                                                    │  cache.regex.exclude check
                                                                    │  ┌──────────────────────────┐
                                                                    ├─►│ /wp-admin /wp-login etc? │── yes ──►  PHP (cache bypassed)
                                                                    │  └──────────────────────────┘
                                                                    │  no
                                                                    │
                                            ┌───────────────────────┴──── HIT
                                            │                              │
                                            ▼ MISS                         ▼
                                      FrankenPHP                  return cached body
                                       (worker)                     from Dragonfly
                                            │
                                            ▼
                                      WordPress
                                            │
                                            ▼
                                  MariaDB / S3 / Redis
                                            │
                                            ▲
                                            │  invalidation: direct Redis DEL
                                            │  from mu-plugin's SouinInvalidator
                                            │  on WP lifecycle hooks
```

Two things to understand before reading anything else:

1. **TLS terminates upstream of Caddy.** In production, Envoy Gateway
   (or whatever Gateway API implementation the cluster uses) handles
   `:443`, then forwards plain HTTP to Caddy on `:8080`. From Caddy's
   POV, every request is `http://`. This matters for the cache key
   shape (see [Limitations](#limitations)).
2. **Anonymous-only caching by design.** The cache is *not* a
   "WordPress cache." It's an "anonymous-public-page cache." The whole
   admin / REST / authenticated surface of WordPress is deliberately
   excluded. This shrinks the invalidation surface area to what we can
   actually keep correct.

## Cache key shape

Souin's cache-handler v0.16.0 generates keys of the form:

```text
GET-<scheme>-<host>-<path>[?<query>]
IDX_GET-<scheme>-<host>-<path>[?<query>]
SURROGATE_<tag>
```

The `IDX_` prefix is cache-handler's index entry pointing at the body
key. `SURROGATE_<tag>` is a Redis SET populated when responses carry a
`Surrogate-Key` header — used for tag-based bulk invalidation.

The shape is documented in `runtime/PHASE-0.md` (the original
phase-0 spike investigation log) and asserted in
`runtime/tests/cache-spike.sh`. **Don't change it without
coordinating with `mu-plugin`'s `SouinInvalidator`** — the mu-plugin
constructs DEL keys from this shape.

## What's cached vs what's bypassed

Two layers of bypass keep auth-aware traffic out of the cache,
regardless of whether the upstream `Cache-Control` header is honoured
by Souin (cache-handler v0.16.0 is unreliable about `no-store` /
`private` directives in upstream responses).

### Layer 1: path bypass

The global `cache` block in the Caddyfile uses `regex.exclude`:

```caddyfile
cache {
    regex {
        exclude (?i)^(/wp)?/(wp-admin/|wp-login\.php|wp-cron\.php|xmlrpc\.php|wp-json/){$FP_CACHE_BYPASS_EXTRA:}
    }
}
```

Covers Bedrock (`/wp/...`) and classic (`/...`) layouts. Any matched
path produces `Cache-Status: Souin; fwd=bypass; detail=EXCLUDED-REQUEST-URI`
and never enters the cache.

`FP_CACHE_BYPASS_EXTRA` is appended verbatim — platform users with
custom auth-aware paths (membership areas, signed-media endpoints,
etc.) set it to e.g. `|^/api/private/|^/members/`.

### Layer 2: cookie bypass

A server-block matcher inspects the `Cookie` header for any of WP's
per-user state cookies and routes those requests around the `cache`
directive entirely:

```caddyfile
@auth_or_state_cookie header_regexp Cookie (wordpress_logged_in_|wp-postpass_|comment_author_)
handle @auth_or_state_cookie {
    try_files {path} {path}/ /index.php?{query}
    php_server
}
```

This is **the layer that closes the auth confidentiality leak** that
prompted the redesign in May 2026. Without it, a logged-in admin's
response could be keyed under the public URL and served to the next
anonymous visitor — exactly what we observed in production.

The three cookies cover:
- `wordpress_logged_in_*` — any logged-in user
- `wp-postpass_*` — visitor unlocked a password-protected post
- `comment_author_*` — visitor recently commented (expects to see it
  before moderation)

### What's still cached

Everything else: anonymous GETs to `/`, `/<slug>/`, `/category/<x>/`,
feeds, sitemaps, attachment URLs. Per the standard HTTP semantics —
upstream `Cache-Control` is honoured, with `FP_CACHE_DEFAULT_CONTROL`
(default `public, s-maxage=300`) as the fallback when the response is
silent.

## Invalidation system

### Why direct Redis DEL

cache-handler v0.16.0's documented HTTP invalidation APIs (`PURGE`
method, POST-CRUD, the `/api.souin/*` admin endpoint) are **broken** in
hard-to-fix ways: PURGE returns 200 but caches the PURGE response
itself rather than invalidating the GET; POST behaves the same; the
admin endpoint returns 404. Full investigation in
`runtime/PHASE-0.md`.

The workaround is to bypass cache-handler's invalidation surface
entirely and operate on the Redis backing store directly. The
`SouinInvalidator` in `mu-plugin` opens a Redis connection from PHP
and `DEL`s cache keys when WordPress fires lifecycle hooks.

### Hook coverage (current, as of mu-plugin v0.3.0)

The heuristic: **bounded change** (one entity → one or two URLs) →
`invalidate_url`; **unbounded change** (anything templated into every
page) → `invalidate_all`. TTL expiry is the safety net for anything
missed.

| Category | Hooks | Action |
|---|---|---|
| Post lifecycle | `save_post`, `wp_trash_post`, `before_delete_post`, `deleted_post`, `clean_post_cache` | DEL post URL + tag + home |
| Global-impact post types | `save_post` for `wp_navigation`, `wp_block`, `wp_template`, `wp_template_part`, `wp_global_styles` | `invalidate_all` |
| Comments | `comment_post`, `transition_comment_status` | DEL parent post |
| Term lifecycle | `created_term`, `edited_term`, `delete_term` | `invalidate_all` |
| User lifecycle | `profile_update`, `user_register`, `deleted_user` | DEL author archive + home |
| Site-wide | `switch_theme`, `permalink_structure_changed`, `wp_update_nav_menu`, `customize_save_after`, `activated_plugin`, `deactivated_plugin` | `invalidate_all` |
| Options | `updated_option` for `blogname`, `home`, `siteurl`, `permalink_structure`, `sidebars_widgets`, `widget_*` | `invalidate_all` |

### Hook timing pitfalls

- **`deleted_post` fires AFTER the row is gone.** `get_permalink( $post_id )`
  returns false at that point, so anything that depends on
  reconstructing the URL silently no-ops. Always pair it with
  `wp_trash_post` and `before_delete_post`, which fire BEFORE the
  state change with the canonical URL still resolvable. The
  invalidator keeps `deleted_post` registered as a defence-in-depth
  no-op safety net.
- **`clean_post_cache` fires mid-flight during trash.** At that point
  `post_status='trash'` and the slug may already be `__trashed`-suffixed,
  so `get_permalink` returns a different URL than what's actually
  cached. Don't rely on it as the only invalidation hook for
  trash/delete flows.

## Limitations

These are deliberate non-fixes — places we know about, looked at, and
chose not to address because the cost-to-benefit didn't justify it.
Don't re-investigate these without a fresh reason.

### 1. Cache keys say `http://` even on TLS-terminated sites

You'll see `Cache-Status` headers like:

```text
cache-status: Souin; hit; key=GET-http-frankenpress.tech-/
```

even when the client clearly used `https://`. This is because:

- TLS terminates upstream of Caddy (Envoy Gateway / Cloudflare / LB),
  so Caddy sees plain HTTP from inside the cluster.
- Caddy 2.7.x's `trusted_proxies` directive **only governs client-IP
  propagation** (`X-Forwarded-For`). It does *not* propagate
  `X-Forwarded-Proto` into the request scheme that downstream modules
  read. Verified empirically: a curl from `localhost` (definitively in
  `private_ranges`, definitively trusted) with `X-Forwarded-Proto: https`
  set explicitly — Souin still keys under `http`.

Why we left it as-is:

- **Confidentiality is fine.** Authenticated requests are bypassed
  entirely via the cookie matcher; the http-keyed entry never holds
  authenticated content.
- **Invalidation is fine.** `mu-plugin` v0.2.1+'s `SouinInvalidator`
  DELs both `GET-http-...` and `GET-https-...` variants on every write
  (the dual-scheme DEL). Whichever scheme Souin happened to key under,
  the DEL hits.

The two ways to flip the key to `https` natively:

- (a) `cache_keys { .+ { disable_scheme } }` in the cache block —
  drops scheme from the key shape entirely. Requires a coordinated
  update to the invalidator (extra DEL form) and a one-time cache
  flush at rollout.
- (b) An out-of-tree Caddy plugin that rewrites `r.URL.Scheme` from
  `X-Forwarded-Proto`. Pulls another upstream pin into xcaddy.

Neither is worth the platform churn for what amounts to a header value
that says `http` instead of `https`.

### 2. `clean_post_cache` is registered but partially redundant

Once `wp_trash_post` and `before_delete_post` are in place, the
`clean_post_cache` hook is mostly redundant — it fires at moments when
`get_permalink` returns the wrong URL anyway. We keep it registered
because it's cheap (a no-op on misses) and because plugins / custom
flows may call `clean_post_cache` outside the standard
trash/delete path; in those cases it acts as a safety net.

### 3. Author archive invalidation on profile change is best-effort

`on_user_change` invalidates the author archive URL plus the home page,
but does **not** enumerate every post the user has authored. Posts
authored by them still carry the old author display name / link in
their byline until their own TTL expires.

The trade-off: enumerating every authored post is a heavyweight DB
query at hook time, and the use case (display name change) is rare.
We accept up-to-`FP_CACHE_TTL` staleness on author byline rendering on
old posts in exchange for cheap, predictable hook execution.

### 4. cache-handler v0.16.0 ignores upstream `Cache-Control: no-store`

Empirically, cache-handler will sometimes cache responses that
explicitly tell it not to. We don't rely on `no-store` to keep
sensitive content out of the cache — the path / cookie bypass layers
above are the load-bearing protections. Future cache-handler upgrades
might fix this; not a reason to weaken either bypass layer.

## Bug chronology (May 2026)

For posterity, in the order encountered:

| # | Symptom | Root cause | Fix shipped in |
|---|---|---|---|
| 1 | Media uploads land in S3 with `Content-Length: 0` | humanmade/s3-uploads sends `x-amz-acl: public-read` on every PUT; AWS buckets with Object Ownership = "Bucket owner enforced" reject the ACL header and abort the upload mid-stream | `mu-plugin v0.2.0` (default `S3_UPLOADS_OBJECT_ACL` to `null`) |
| 2 | Edits saved but not reflected on view | Souin keying with `http`, mu-plugin DELing with `https` — scheme drift, invalidator never matches the cached entry | `mu-plugin v0.2.1` (dual-scheme DEL) — the architecturally correct fix in `runtime` is partial (see Limitation 1) |
| 3 | Trashed/deleted post still served from cache | `deleted_post` hook fires AFTER the row is gone, `get_permalink` returns false, invalidator no-ops | `mu-plugin v0.2.2` (added `wp_trash_post` + `before_delete_post` hooks) |
| 4 | Logged-out browser served logged-in admin HTML; wp-admin pages cached in Redis | cache-handler v0.16.0 ignores upstream `Cache-Control: no-store, private` | `runtime v0.1.4` (path + cookie bypass at the cache layer; doesn't depend on upstream Cache-Control being honoured) |
| 5 | Term changes / menu updates / customizer saves leave cache stale | `SouinInvalidator` only hooked post lifecycle + a curated handful of options — most WP write events not covered | `mu-plugin v0.3.0` (expanded hook coverage for terms / users / menus / customizer / plugin activation / widget options / global-impact post types) |

The first two were a single deployment incident — the user clicked
delete on cached "ghost" posts that they didn't realise had already
been deleted at the DB level (because the cached front-end still
showed them). By the time the cache layer was diagnosed, real content
had been deleted. **There were no MariaDB backups configured in the
cluster** (the IAM user existed but the `Backup` CR was never written
in the gitops repo); recovery was not possible.

Operational lesson: provision backups *before* the first incident, not
after. Tracked separately as a gitops repo follow-up.

## Operating the cache

### Diagnostics

```bash
# Inspect the cache-handler config Caddy actually loaded
kubectl -n <ns> exec deploy/<release>-site -- \
  curl -s http://localhost:2019/config/apps/http/servers/srv0/trusted_proxies | jq .

# What keys are currently in the cache
kubectl -n <ns> exec <redis-pod> -- redis-cli --scan --pattern 'GET-*' | head -50

# Cache-Status of any URL
curl -sI 'https://<host>/<path>' | grep -i cache-status

# Force-flush all Souin keys (does NOT touch other Redis users)
for pattern in 'GET-*' 'IDX_*' 'SURROGATE_*'; do
  kubectl -n <ns> exec <redis-pod> -- redis-cli --scan --pattern "$pattern" \
    | xargs -r kubectl -n <ns> exec <redis-pod> -- redis-cli DEL
done
```

### Reading `Cache-Status` headers

| `cache-status` value | Meaning |
|---|---|
| `Souin; fwd=uri-miss; stored` | Cache miss, response forwarded and now cached |
| `Souin; hit; ttl=<s>; key=<k>; detail=REDIS` | Cache hit served from Redis |
| `Souin; fwd=bypass; detail=EXCLUDED-REQUEST-URI` | Path matched the `regex.exclude` list — never cached |
| (no header) | Request matched `@auth_or_state_cookie` and skipped the cache directive entirely |
| `Souin; fwd=uri-miss; detail=UPSTREAM-ERROR-OR-EMPTY-RESPONSE` | Upstream returned an empty body or errored — not cached |

## Related code

- `runtime/Caddyfile` — global `cache` block, `regex.exclude`, and
  the `@auth_or_state_cookie` matcher
- `mu-plugin/src/SouinInvalidator.php` — hook registration and
  invalidation logic
- `runtime/tests/cache-spike.sh` — integration tests asserting the
  cache + bypass behaviour
- `runtime/PHASE-0.md` — original Phase 0 investigation log

## Related PRs (May 2026 cache-correctness rollup)

| Repo | PR | What |
|---|---|---|
| `mu-plugin` | #5 (v0.2.0) | Default `S3_UPLOADS_OBJECT_ACL` to `null` |
| `mu-plugin` | #6 (v0.2.1) | Dual-scheme DEL in `invalidate_url` |
| `mu-plugin` | #7 (v0.2.2) | Hook `wp_trash_post` + `before_delete_post` |
| `mu-plugin` | #8 (v0.3.0) | Hook expansion for terms / users / menus / customizer / plugins / widgets / global-impact post types |
| `runtime` | #13 (v0.1.3) | `trusted_proxies` for client-IP propagation |
| `runtime` | #14 (v0.1.4) | Path + cookie cache bypass |
| `runtime` | #15 | Comment-only fix — corrects the wrong claim about `trusted_proxies` and scheme |
| `docs` | #13, #14, #15 | Cacheability model + hook table + scheme note |
| `eoe` | #4, #5 | Site-image bumps to pull in the runtime + mu-plugin changes |

## When to update this doc

- A new hook is added to or removed from `SouinInvalidator` →
  update the hook coverage table.
- The cache key shape changes (e.g. taking the `disable_scheme` path
  from Limitation 1) → update [Cache key shape](#cache-key-shape) and
  the [Limitations](#limitations) entry.
- A new `FP_CACHE_*` env var is added → add a row in the bypass
  section.
- A new bug class is hit and fixed → add a row to the
  [Bug chronology](#bug-chronology-may-2026) (rename the section if
  the chronology grows past one window).
