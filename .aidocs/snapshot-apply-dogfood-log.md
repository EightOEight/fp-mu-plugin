# Snapshot-apply dogfood log — May 2026

**Status:** done (reference)
**Dates:** 2026-05-11 → 2026-05-12 (one long working session)
**Outcome:** Designer-promotion workflow shipped end-to-end to sts-stg on N27a.

Chronological log of the first end-to-end designer-snapshot dogfood
through FrankenPress. Captured for future-session pickup + as a
reference for what worked, what didn't, and why. Companion to
[`job-mutable-fs-for-apply.md`](./job-mutable-fs-for-apply.md) (the
architectural decision doc).

## What we set out to do

Promote a designer's locally-built site (The7 "FSE Corporate"
Pre-Made Website Template, ~184 imported posts + ~55 scoped options)
from docker-compose into sts-staging on N27a — through the
adapter-scoped, WXR-based, additive snapshot architecture introduced
in mu-plugin v0.8.0 + charts v0.9.0.

The shipping artifact: `web/imports/<slug>/` committed in the site
repo, baked into the immutable image at build time, applied by the
chart's install Job via `wp fp apply`.

## Phase 1 — Capture (docker-compose)

Snapshot capture against sts's local stack with The7 FSE Corporate
imported via The7's UI importer (the LDE relaxation gated on
`KUBERNETES_SERVICE_HOST` makes that work).

**Bugs surfaced + fixed:**

| Bug | Fix | Released |
|---|---|---|
| `WP_CLI::runcommand('export', ...)` passed wp-cli flags as runcommand options, not the inner command — `wp export` ran but ignored flags | Build flags into the command string via `sprintf('export --post__in=%s ...', ...)` | mu-plugin v0.8.1 |
| `launch => false` killed parent — `wp export` calls `exit()` internally; in-process invocation terminated outer `wp fp snapshot` silently with no diagnostic | `launch => true` + `exit_error => false` for the runner closure | mu-plugin v0.8.2 |
| `wp export` has no `--filename` flag — silently ignored; output landed at `wordpress.YYYY-MM-DD.xml` in cwd, not the manifest-declared path | Use `--stdout`, capture from runcommand's `result.stdout` | mu-plugin v0.8.3 |
| `WP_CLI::runcommand` w/ `launch => true` deadlocked at ~750KB on a 184-post export; required SIGKILL | Replace with direct `proc_open` of `wp export`; explicit `/dev/null` stdin | mu-plugin v0.8.4 |
| Stderr-to-pipe in the v0.8.4 `proc_open` also deadlocked once the kernel's ~64KB stderr buffer filled (wp export emits a progress line per post) | Target child stderr at a regular file via `proc_open` descriptor spec; kernel handles drain, no buffer ceiling | mu-plugin v0.8.5 |
| `resolve_output_dir` in Bedrock layout landed at `/app/web/web/imports/<slug>/` (double `web/`); the `preg_replace('#/wp$#', '', $root)` trick stripped only one segment | `dirname($abspath, 2)` to climb both `web/wp` segments to site root cleanly | mu-plugin v0.8.5 |

**Capture output:** all 5 artefacts (manifest.yaml/json,
content.xml.gz, options.json, composer-patch.json,
uploads-manifest.txt) at the correct Bedrock path. 184 posts
captured, 55 scoped options, sha256-verified.

Surfaced two findings to flag:
- `composer-patch.json#/unresolved` listed 4 plugins (3 theme-bundled
  + contact-form-7). Only contact-form-7 is wpackagist-resolvable.
- `uploads_file_count: 0` despite The7 FSE Corp having a full image
  set. The7 attaches via `attachment` post_type, which the current
  adapter scope doesn't cover. (Real gap, noted as a follow-up.)

## Phase 2 — Land the snapshot in the site repo (sts)

Single PR against sts: commit `web/imports/fse-corp/` + add
`wpackagist-plugin/contact-form-7` to composer.json (the one
wpackagist hit from the patch). 7 files, +2700/-3.

[EightOEight/sts#11](https://github.com/EightOEight/sts/pull/11) →
tag v0.0.8.

## Phase 3 — First staging deploy (v0.0.8): install Job CrashLoopBackOff

gitops-fp PR bumped `sts.imageTagStg: v0.0.7 → v0.0.8`. ArgoCD
reconciled. Install Job got partway then exit-1'd with **zero
output** on stdout AND stderr.

**Diagnosis via `kubectl exec` on the live site Pod (read-only):**
- The fp command surface was registered correctly
- `wp fp apply --snapshot-dir=...` exited 1 with no logs
- `wp eval`-running the apply path directly (with proper `exit_error =>
  false` wrapper) surfaced the actual error:
  ```
  Warning: Could not create directory. "/app/web/app/upgrade"
  Error: No plugins installed.
  ```

**Root cause:** `Restorer::import_wxr()` called `wp plugin install
wordpress-importer --activate`. That's structurally impossible
against the FrankenPress runtime: `/app/web/app/upgrade` doesn't
exist on the read-only filesystem, `DISALLOW_FILE_MODS=true`, and
the in-process `WP_CLI::error` inside `runcommand(launch=>false,
exit_error=>true)` killed the outer process silently.

**Fix (mu-plugin v0.8.6):**
- Drop the runtime `wp plugin install` call
- Replace with `require_wxr_importer()` precondition + actionable
  error string
- Wrap the apply path's `wp_runner` to surface inner non-zero exits
  as `RuntimeException` (bubbles to `Command::apply`'s existing
  `catch (Throwable)` → `WP_CLI::error()`)

Plus consumer-side stopgap: sts v0.0.9 + site-template added
`wpackagist-plugin/wordpress-importer ^0.9.5` to composer so the
plugin is baked into the image.

## Phase 4 — v0.0.9 (still crashloop): inactive plugin

v0.0.9 install Job's improved error surface revealed v0.8.6's
precondition was too strict:
```
Error: snapshot apply requires the WP-Importer plugin to be installed
and active. Add `wpackagist-plugin/wordpress-importer` (^0.9) to your
site repo's composer.json...
```

The plugin **was** on disk (composer baked it in) but composer
doesn't *activate* WordPress plugins — that's a separate
`active_plugins` option write.

**Fix (mu-plugin v0.8.7):** extend `require_wxr_importer()` to
check disk → if present-but-inactive, activate it via `wp plugin
activate` (a pure option write, safe under the lockdown).

**Outcome:** plugin activated on next attempt, but the apply path
now hit the deeper bug…

## Phase 5 — v0.0.10 (designed but not deployed): user observation

While v0.0.10 was building, the user observed: *"Really the jobs
don't need the read only file system, and it feels like the helm
chart could toggle it on and off in the image."*

That suggestion redirected the whole architecture. The install Job
is ephemeral, single-shot, no traffic — different threat model from
long-running web Pods. RW root FS on Job pods only is safe AND lets
`wp plugin install` work at runtime. The image is never actually
mutated (overlay only; GC'd with the pod).

Halted v0.0.10's gitops bump. Pivoted to the chart-side approach.
Wrote [`job-mutable-fs-for-apply.md`](./job-mutable-fs-for-apply.md)
to capture the design and shelved the alternative
`inline-wxr-importer.md` proposal.

## Phase 6 — The cascade (the new design)

Four-repo coordinated landing:

| Order | Change | Released |
|---|---|---|
| 1 | site-template `application.php` gates lockdown on `(KUBERNETES_SERVICE_HOST AND !FP_ALLOW_FILE_MODS)`. Required because Bedrock's `Config::define()` throws `ConstantAlreadyDefinedException` on redefine — pre-defining via wp-cli `--require=` was impossible. Env-var gate is the clean answer. | site-template v0.2.7 |
| 2 | charts: install Job drops `readOnlyRootFilesystem: true` AND sets `FP_ALLOW_FILE_MODS=1` env on the Job container only. e2e lockdown-negative-test updated to run against site-template v0.2.7. Verified `DISALLOW_FILE_MODS=true` still holds on the web Pod. | charts v0.10.0 |
| 3 | mu-plugin `Restorer.ensure_wxr_importer()` returns one of `borrowed` / `activated` / `installed` modes. `installed` mode: `wp plugin install` runs transiently inside the Job pod's overlay, with a `finally` block that deactivates the plugin before pod exit (so `active_plugins` doesn't reference a missing file in the next web Pod's RO image). | mu-plugin v0.9.0 |
| 4 | sts mirrors site-template's `application.php` gate (cascade fact — the change is in every consumer site's `config/`, not in mu-plugin). | sts v0.0.11 |

Plus discovered the hard way:

**Chart.lock pitfall.** Accidentally `git add -A`'d `base/helm/site/Chart.lock` in the gitops-fp PR. The lock pinned an old chart version (0.7.0); ArgoCD's `helm dependency build` then refused to render `sts-stg / eoe-stg / eoe-prd` for ~9h with:
```
Error: the lock file (Chart.lock) is out of sync with the dependencies
file (Chart.yaml). Please update the dependencies
```
Fix: untrack + `.gitignore` it. ArgoCD calls `helm dependency update`
(not `build`) when no lock is present, resolves fresh against OCI
every time. Pin versions via Chart.yaml's `version:` field, not
Chart.lock.

## Phase 7 — v0.0.11 deploy (close but not perfect)

ArgoCD reconciled to v0.0.11 + charts v0.10.0. New install Job got
much further but still exit-1'd. Pod logs got pruned by
`BackoffLimitExceeded` before kubectl could capture them.

Recovery path (user-driven): delete the Failed Job → ArgoCD
recreated → grabbed logs from the live retry pod before next backoff:

```
Error: apply failed: inner wp-cli command
"import '/tmp/fp-wxr-TEZIxq.xml' --authors=skip --skip=image_resize"
exited 1: Error: WordPress Importer needs to be activated.
Try 'wp plugin activate wordpress-importer'.
```

But `wp plugin is-active wordpress-importer` returned 0 (active!).
And `class_exists('WP_Import')` returned false. Function exists,
plugin "active" — class missing.

**Root cause (the deepest bug):** WP-Importer's main file
early-returns unless `WP_LOAD_IMPORTERS` is defined:
```php
if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) {
    return;
}
```

When WP loaded plugins at wp-cli's bootstrap, `WP_LOAD_IMPORTERS`
wasn't yet defined, so WP-Importer's main file early-returned —
file was `require_once`'d but its meaningful body never executed.
`WP_Import` class never defined.

wp-cli's `import` command package has a `load_import_class()`
helper that defines `WP_LOAD_IMPORTERS` BEFORE its require — but
ONLY at top-level command dispatch. `WP_CLI::runcommand('import
...', ['launch' => false])` runs the inner command in the same
process where the file was already require'd; PHP's `require_once`
won't re-execute. So `load_import_class()` runs but its
`require_once` is a no-op, and `class_exists` check still fails.

**User chose option B (finish manually + ship fix as follow-up):**

Manually completed what the apply path couldn't:
1. `wp import` — directly as top-level command (works) → all 184 posts
2. Applied options.json — 55 options + 4 dt-the7 theme_mods via PHP loop
3. URL retarget — `wp search-replace localhost:8080 →
   staging.soletradersupport.co.uk` → 319 replacements
4. The7 dynamic-CSS regen — **failed silently** (function undefined; see Phase 8)
5. Set markers — `fp_snapshot_applied_ref` + `fp_snapshot_applied_sha256`

Sts-stg now serving correct content. Install Job still failed
because of the postDeployCommand fatal (next phase).

## Phase 8 — postDeployCommand fatal

Install Job retry got past `wp fp apply` (markers matched → skipped)
but fataled on the postDeployCommand:
```
PHP Fatal error: Call to undefined function
the7_maybe_regenerate_dynamic_css()
```

**Root cause:** The7's `inc/init.php` conditionally loads
`dynamic-stylesheets-functions.php`:
```php
if ( ! the7_is_gutenberg_theme_mode_active() ) {
    require_once PRESSCORE_DIR . '/dynamic-stylesheets-functions.php';
}
```
FSE Corporate uses Gutenberg theme mode (FSE templates) → file never
loaded → function never defined.

**Fix (gitops-fp PR #19):** wrap postDeployCommand in
`function_exists()`:
```yaml
postDeployCommands:
  - 'eval "if(function_exists(\"the7_maybe_regenerate_dynamic_css\")){...}"'
```

Classic-template sites still run the regen; FSE-mode sites no-op.

## Phase 9 — v0.0.11 stable

After the function_exists guard, next install Job hash:
`site-install-0f6d230c` Complete in **9 seconds**. ArgoCD Synced +
Healthy. Site serving The7 FSE Corporate.

```
[snapshot] applying /app/web/imports/fse-corp
+ wp --allow-root --path=/app/web/wp fp apply --snapshot-dir=...
snapshot already applied (idempotency markers matched); no-op
Success: apply skipped
[post-deploy] running 1 command(s):
+ wp eval if(function_exists("the7_maybe_regenerate_dynamic_css")){...}
[install] done
```

## Phase 10 — Real fix for the import bug (mu-plugin v0.9.1)

With sts-stg working, swung back to fix the actual import dispatch
bug.

Considered three options:
1. Pre-load WP-Importer in parent process — failed (require_once
   no-op)
2. Change `wp_runner` to `launch => true` — affects all inner
   commands, slower
3. Bypass `wp_runner` for the import call only, use direct
   `proc_open` of `wp import` as a fresh subprocess

Picked option 3. Same pattern WxrCapturer uses for `wp export`.
Fresh process → wp-cli's top-level dispatch path runs →
`load_import_class()` defines the constant + requires fresh →
`WP_Import` defined → import proceeds.

Verified locally: `wp fp apply` against the local sts stack (markers
cleared first) exited 0 with `Success: apply complete`, both markers
populated, wordpress-importer left in 'borrowed' state.

Tagged mu-plugin v0.9.1.

## Phase 11 — Revert composer stopgap + final v0.0.12

With mu-plugin v0.9.1 capable of managing WP-Importer transiently:
- sts dropped `wpackagist-plugin/wordpress-importer` from composer
- site-template dropped the same dep
- Tag sts v0.0.12 → gitops bump

Final install Job `site-install-79b44dfa` Complete. Synced/Healthy.

**Unverified path remaining:** the install-activate-use-deactivate
loop in `ensure_wxr_importer().installed` mode has only been
verified locally. On sts-stg, the markers from Phase 7's manual
completion mean every subsequent apply runs `Success: apply skipped`
via `already_applied()`. The next fresh snapshot (different
`fp_snapshot_applied_ref`) will be the first to actually trigger
the full loop end-to-end on staging.

## What got built

```
Repo                          Tag             What
─────────────────────────────  ──────────────  ────────────────────────────────
frankenpress/mu-plugin         v0.8.1 - v0.8.7 incremental fixes during dogfood
frankenpress/mu-plugin         v0.9.0          Restorer install-activate-use-deactivate cycle
frankenpress/mu-plugin         v0.9.1          wp import via fresh proc_open subprocess (real fix)
frankenpress/charts            v0.10.0         install Job RW root FS + FP_ALLOW_FILE_MODS=1 env
frankenpress/site-template     v0.2.7          application.php FP_ALLOW_FILE_MODS gate
                                               (then: dropped wordpress-importer dep)
EightOEight/sts                v0.0.8          first snapshot landing
EightOEight/sts                v0.0.9          + wordpress-importer composer dep (stopgap)
EightOEight/sts                v0.0.11         + application.php FP_ALLOW_FILE_MODS gate
EightOEight/sts                v0.0.12         - dropped wordpress-importer (final shape)
aypex-io/gitops-fp                             Chart.lock gitignored;
                                               sts.imageTagStg → v0.0.12;
                                               charts dep → 0.10.0;
                                               postDeployCommand function_exists guard
```

## Lessons learned (worth keeping)

1. **Job pods are a different threat model than web Pods.** RW root
   FS on ephemeral, single-shot Jobs is fine. Don't blanket-apply
   the lockdown that protects long-running web Pods.

2. **Bedrock's `Config::define` is strict.** Throws on any redefine,
   regardless of value match. Pre-defining constants via wp-cli's
   `--require=` to "win the race" against application.php does not
   work. Use env-var gates inside application.php instead.

3. **PHP `require_once` is one-shot per process.** Once a file is
   in `get_included_files()`, no amount of defining constants after
   the fact will cause re-evaluation. Plugins that early-return on
   missing constants need a fresh subprocess OR a `require` (not
   `require_once`) wrapper.

4. **`WP_CLI::runcommand(..., launch=>false)` shares parent's PHP
   state.** Most things work; the import-command's
   `WP_LOAD_IMPORTERS` initialization doesn't, because by the time
   the helper tries to require the plugin file, it's already
   `require_once`'d (and early-returned). Use `proc_open` for the
   problem child.

5. **k8s Job-controller prunes pods on `BackoffLimitExceeded`.**
   Logs vanish with them. For debugging a CrashLoopBackOff: delete
   the Failed Job → ArgoCD recreates → grab logs in the brief
   live-pod window before next backoff exhausts.

6. **Chart.lock vs Chart.yaml drift = silent ArgoCD render failure.**
   In a gitops repo, either commit Chart.lock AND keep it in sync
   on every bump, or gitignore it and let the repo-server regenerate.
   Don't accidentally `git add -A` partial state.

7. **The7 FSE-mode lacks classic-template functions.** Any
   `postDeployCommand` referencing classic-The7 functions
   (`the7_maybe_regenerate_dynamic_css`, etc.) must guard with
   `function_exists()`. FSE-mode uses `wp_global_styles` not LESS
   compilation; the regen is unnecessary anyway.

8. **`composer.lock` gitignored on sts** means each fresh image
   build re-resolves `^0.8.0` (or `^0.9.0`) against the latest
   matching tag. After cutting mu-plugin v0.9.1, simply re-tagging
   sts triggered a build that picked up the new version with no
   constraint change needed.

## Open follow-ups (not blocking)

- **Verify the transient-install loop on staging** by landing a
  fresh designer snapshot with a new `fp_snapshot_applied_ref`.
- **Attachment scope.** The7 attaches FSE-Corp images via
  `attachment` post_type, which the current adapter scope doesn't
  cover. Images won't render until `The7::scope()` extends to
  include `attachment` posts carrying `_the7_imported_item` (or
  descendants of in-scope posts).
- **Theme-bundled plugin recognition.** `composer-patch.json` flags
  `better-block-editor`, `better-block-editor-pro-kit`,
  `dt-the7-post-types` as unresolved; they're bundled via The7's
  theme. Could be silenced by teaching the patch logic to recognise
  theme-bundled plugin paths or by extending
  `The7::documented_exclusions()`.
