# Job-scoped mutable root FS for `wp fp apply`

**Status:** proposed → in progress
**Originated:** 2026-05-11 — user observation after the wordpress-importer
composer-dep approach landed on sts (v0.0.9 / mu-plugin v0.8.6+v0.8.7)
**Supersedes:** `inline-wxr-importer.md` (workspace `~/Developer/frankenpress/.aidocs/`, shelved) — that
proposal is a more invasive answer to the same underlying problem this
solves directly.

## The problem this solves

The first end-to-end designer snapshot dogfood (May 2026) made two
costs of the immutable-image lockdown visible at the apply boundary:

1. **WP-Importer can't be installed on demand.** `wp plugin install`
   needs a writable `/var/www/.wp-cli/cache/` + `/app/web/app/upgrade/`
   + `/app/web/app/plugins/`. All three are read-only or absent under
   the runtime's `readOnlyRootFilesystem: true` posture. v0.8.6 worked
   around this by requiring consumer sites to `composer require
   wpackagist-plugin/wordpress-importer` so the plugin is baked into
   the image.

2. **Every consumer site now carries that requirement.** sts had to
   add the dep (PR #12), the FrankenPress template added it (PR #27),
   and every future site repo inherits the coupling. That's a hidden
   contract: "if you want `wp fp apply` to work, you must remember to
   composer-require this plugin." Footgun by deferred error message.

The deeper observation: **the read-only root FS is the right posture
for long-running web Pods (real attack surface — webshells, supply-
chain mutations, file-level persistence) but is overkill for the
install / wpcron Jobs**, which are:

- Ephemeral (run once, exit)
- No exposed network listener
- Already running privileged WP-CLI commands as root
- Inherently mutating state (DB writes, theme activation, snapshot
  apply) — the "immutability" property is already not what protects
  them

If the Job container's root FS is overlay-writable, `wp plugin
install` works. The image isn't actually mutated (overlay is per-pod;
GC'd when the Job completes). The web Pod that follows is still
read-only and unchanged.

## Design

### Chart side (`charts/site/templates/job-install.yaml`)

Drop `readOnlyRootFilesystem: true` from the install Job container's
`securityContext`. Keep everything else: `runAsUser: 33` (www-data),
`runAsNonRoot: true`, `allowPrivilegeEscalation: false`, `drop: [ALL]`,
`seccompProfile.type: RuntimeDefault`. The lone change is RO → RW.

The Deployment / web Pod containerSecurityContext is unchanged. The
chart already templates the Job's securityContext separately, so this
is a localized override.

Same change applies to `cronjob-wpcron.yaml` if we later need it for
wpcron tasks; for now, keep it RO since wpcron doesn't need to install
anything.

PSA `restricted` compatibility: dropping `readOnlyRootFilesystem` is
*not* one of the criteria PSA `restricted` enforces. PSA restricted
requires `allowPrivilegeEscalation: false` + `runAsNonRoot: true` +
`seccompProfile` + capability drop — all still present. Verified
against the v1.29 PSA spec.

### mu-plugin side (`Cli/Apply/Restorer.php`)

`require_wxr_importer()` becomes `ensure_wxr_importer()`, with a
three-step manage cycle:

1. **Check** if `wordpress-importer/wordpress-importer.php` is in
   `active_plugins`. If yes, treat as borrowed (someone baked it in
   on purpose) — don't touch it.
2. **Check** if the plugin file exists under `WP_PLUGIN_DIR`. If yes
   but inactive (v0.8.7's path), activate it. Don't deactivate later
   — the site is using it for their own reasons.
3. **Otherwise install + activate** via `wp plugin install
   wordpress-importer --activate`. Register a teardown that
   deactivates the plugin after the apply completes (try/finally
   pattern). The plugin file is in the Job's overlay; it disappears
   when the pod GC's, but the `active_plugins` option is in MariaDB
   and persists, so we must clear it before pod exit. Otherwise the
   next web Pod loads `active_plugins` and tries to require a
   plugin file that doesn't exist → PHP warning on each request.

The teardown deactivation runs in `finally`, so a failed import still
leaves a clean `active_plugins`. Idempotent because deactivating an
already-inactive plugin is a no-op (wp-cli prints a warning, returns 0).

### Snapshot-declared apply-time plugins (Phase 2 — not in this PR)

Once the apply path can transiently install plugins, snapshot manifests
can declare additional ones:

```yaml
apply_time_plugins:
  - slug: slider-revolution-lite  # if a wpackagist match exists
  - slug: js-composer              # WPBakery import dep
```

The Restorer would install + activate each one before WXR import,
deactivate them all in `finally`. Premium plugins without wpackagist
hosts continue to need a private composer repo or manual bundling —
that's a separate problem. Phase 2 is deferred until we have a second
adapter (Divi / Avada) that proves the need.

## What goes away

- **Consumer sites stop having to `composer require
  wpackagist-plugin/wordpress-importer`.** It's only needed in the
  install Job's transient overlay.
- **`frankenpress/site-template`'s composer.json** drops the dep
  (revert of #27).
- **`EightOEight/sts`'s composer.json** drops the dep (revert of #12).
- **mu-plugin v0.8.6's `require_wxr_importer()` precondition error**
  becomes vestigial — the path that triggers it (plugin absent +
  no install ability) no longer exists. v0.8.7's auto-activate
  branch stays as the "site has it baked in" fast-path.

## What stays the same

- **Web Pod immutability.** The lockdown's primary purpose
  (preventing UI-driven plugin/theme installs from landing on
  ephemeral disk) is unaffected.
- **Adapter-scoped, additive apply semantics.** None of this changes
  what `wp fp apply` does at the WordPress level — only what's
  available to it at execution time.
- **DISALLOW_FILE_MODS=true** in the site's wp-config. That blocks
  the wp-admin UI plugin installer (because `request_filesystem_credentials`
  short-circuits). wp-cli's `wp plugin install` doesn't go through
  that gate — verified empirically. The `DISALLOW_FILE_MODS`
  constant is a wp-admin runtime control, not a CLI control.

## Migration plan

1. **mu-plugin v0.9.0** — install-activate-use-deactivate cycle in
   `Restorer::ensure_wxr_importer`. New schema flag `apply_time_plugins`
   parsed but ignored for now (forward-compat).
2. **charts v0.10.0** — install Job container drops
   `readOnlyRootFilesystem: true`. Bumped `appVersion` if a coordinated
   site-template / runtime bump is required (probably not).
3. **gitops-fp** — bump charts dep to v0.10.0; reconcile sts-stg.
   This is the merge that ArgoCD actually acts on; standard pause-
   for-go-ahead applies.
4. **sts + frankenpress/site-template** — revert the
   wpackagist-plugin/wordpress-importer requires once v0.9.0 +
   v0.10.0 are live and the dogfood snapshot apply succeeds without
   the bake-in.
5. **Phase 2 (deferred):** snapshot-declared `apply_time_plugins`.

## Risks + mitigations

| Risk | Mitigation |
|---|---|
| Job overlay grows large during apply (plugin downloads + tmp extract) | tmpfs sizes already generous (`/tmp` 64Mi default); plugin downloads typically < 5MB |
| wp plugin install needs network egress to api.wordpress.org | sts already has Internet egress for s3-uploads + Postmark; no new requirement |
| Active_plugins not cleaned on Job failure | try/finally pattern ensures cleanup; integration test asserts active_plugins identical after apply (success or failure) |
| Future Job containers accidentally relying on writable FS for *persistence* | Document clearly in chart README; the writable FS is for *transient* state only |
| PSA `restricted` would block the relaxation | Verified `readOnlyRootFilesystem` is NOT one of PSA restricted's criteria |

## Acceptance criteria

- [ ] charts v0.10.0 published; install Job uses RW root FS
- [ ] mu-plugin v0.9.0 published; Restorer manages WP-Importer lifecycle
- [ ] sts dogfood: snapshot apply succeeds without
      `wpackagist-plugin/wordpress-importer` in `composer.json`
- [ ] `wp option get active_plugins` after a successful apply does
      NOT contain `wordpress-importer/wordpress-importer.php`
- [ ] Web Pod still rejects writes to `/app/web/app/plugins/` (RO
      check still in effect — only the install Job is relaxed)
