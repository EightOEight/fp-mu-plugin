# .aidocs — mu-plugin design notes

Long-form design notes, ADRs, and decision logs for the FrankenPress
must-use plugin. Lives in the mu-plugin repo (not the workspace-wide
`.aidocs/`) when the content is mu-plugin-specific enough that it
belongs alongside the code.

## Conventions

- **One file per topic.** Don't accumulate everything in a single backlog.
- **Lead with context** (the "why") before solution.
- **Mark status at the top** — `draft / proposed / accepted / done (reference) / shelved`.
- **Link to PRs + tags** that ship the work, so the file's role shifts from "proposal" to "log" as code lands.

Cross-cutting platform-wide plans (touching runtime + site-template + charts + mu-plugin + docs together) belong in `~/Developer/frankenpress/.aidocs/` at the workspace root, not here.

## Index

| File | Status | Topic |
|---|---|---|
| [`job-mutable-fs-for-apply.md`](./job-mutable-fs-for-apply.md) | done (reference) | Why the chart's install Job runs with `readOnlyRootFilesystem: false` + `FP_ALLOW_FILE_MODS=1` env, and how `Restorer::ensure_wxr_importer()` install-activate-use-deactivate cycle uses that to bring up WP-Importer transiently. Shipped via charts v0.10.0 + mu-plugin v0.9.1 + site-template v0.2.7 + sts v0.0.12 (2026-05-12). |
| [`snapshot-apply-dogfood-log.md`](./snapshot-apply-dogfood-log.md) | done (reference) | Chronological log of the May 2026 first-end-to-end designer-snapshot dogfood. 11 phases, bugs found + fixes (mu-plugin v0.8.1 → v0.9.1), 8 specific lessons captured. Read as a worked example of the snapshot architecture. |
| [`cache-architecture.md`](./cache-architecture.md) | done (reference) | The Souin + `SouinInvalidator` caching architecture, the bypass layers (anonymous-GET vs admin/auth), the hook coverage (`save_post`, `clean_post_cache`, comment status, theme switch, permalink, global option), the deliberate non-fixes (Souin `PURGE` / POST-CRUD / `/api.souin/*` broken in cache-handler v0.16.0), and the May 2026 bug chronology (5 distinct "I changed something but the public site shows the old version" failure modes in 24 hours). Cache key shape (`GET-<scheme>-<host>-<path>`) must agree with `runtime/Caddyfile`. Load-bearing context for any future cache-shaped debugging — moved here from workspace `.aidocs/` 2026-05-14 because the active code surface lives in this repo. |
