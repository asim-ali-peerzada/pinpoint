# Changelog

All notable changes to Pinpoint will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.5.0] - 2026-09-04

### Added

- **Performance regression diff** (`pinpoint:snapshot` / `pinpoint:diff`): capture per-route metrics as a named baseline, then compare after a change. The diff table shows `REGRESSION` / `IMPROVEMENT` / `STABLE` / `NEW` / `REMOVED` per route with baseline-vs-current p95 and query counts; regressions get a detail block with exact deltas, caller file:line, and the suggested fix. `--fail-on-regression` exits 1 for CI, `--json` emits a machine-readable payload. Thresholds in `pinpoint.diff` (`PINPOINT_DIFF_DURATION_PCT=20`, `PINPOINT_DIFF_QUERY_COUNT=3`, `PINPOINT_DIFF_MEMORY_PCT=50`); an introduced N+1 always flags. `pinpoint.diff.min_samples` (`PINPOINT_DIFF_MIN_SAMPLES`, default 1) requires N samples per side before judging a route.
- **`--fail-on-duplicates` CI gate**: fails only on exact-duplicate queries (identical bindings, `Cache::remember()` candidates).
- **Three-state N+1 column**: `Yes (xN)` for true N+1, `CACHE (xN)` (cyan) for exact duplicates, `REPEAT (xN)` (yellow) for repeats with no binding data — the summary, Locate block (`CACHE xN` / `REPEAT xN`), drill-down badges, and `pinpoint:check` types now agree everywhere.
- **Composite Health verdict reasons**: the Health column reads `HEALTHY` or `NEEDS WORK · <reasons>` (`CRITICAL`, `N+1`, `MEMORY`, joined with `·`) instead of the contradictory `NEEDS WORK (GOOD)`; the header is simply `Health`. Presentation only — tier calculation, JSON vocabulary, and config keys are unchanged.
- **Per-route worst-case query counts** in summaries (max `query_count` across samples), available in `pinpoint:report --json` and used by the diff view.

### Fixed

- **`--fail-on-n1` no longer fails on exact duplicates**: the flag now covers true N+1 (varying bindings, lazy-load violations, unclassifiable repeats) — duplicates fail only under `--fail-on-duplicates`.
- **No-binding repeats no longer labeled N+1** in the summary table or Locate block (they are `unknown` in the drill-down; now `REPEAT` everywhere).
- **`pinpoint:report --json` and `pinpoint:diff --json` on empty/missing data** now emit valid JSON (`{"meta": {"empty": true}, "routes": []}` / `{"meta": {"error": ...}, "routes": []}`) instead of plain text that broke `jq` and CI parsers.
- **Flush no longer re-runs in long-running processes**: the terminating callback is registered once at boot and flushes a payload staged on the scoped recorder, instead of registering a new callback per request (Laravel re-runs every registered callback, which duplicated rows under Octane/workers/tests).
- **CI detection is complete**: repeat-pattern detection no longer stops at a top-20 cutoff, so a gate can never false-green because the offending group sat below it.

### Docs

- README: `pinpoint:snapshot` / `pinpoint:diff` workflow, `--fail-on-duplicates` semantics, the three-state N+1 column, and the Health verdict format.
- New `AGENTS.md` testing mandate (full suite + Pint + PHPStan before/after every change, adversarial coverage rules per `docs/testing-plan.md`).

## [1.4.3] - 2026-09-02

### Fixed

- **Pinpoint no longer records its own writes**: the flush-time inserts (`pinpoint_requests`, `pinpoint_queries`, `pinpoint_lazy_loads`) fired `QueryExecuted`, were captured back into the recorder, and stored as real queries. Every request carried a phantom `insert into pinpoint_requests` row whose caller resolved to the bootstrap file, so even zero-query routes showed `n1_repeat=1` and Locate rendered `N+1 x1` for the critical tier. `Recorder::flush()` now flips a guard that makes `recordQuery()` ignore Pinpoint's own inserts.
- **Exact duplicates no longer counted as N+1**: the summary repeat count included duplicate query groups (same SQL + same bindings → `Cache::remember()` candidates), so a duplicate-only route showed `N+1? Yes (x3)` in the table while the headline separately credited it as "with duplicate queries". `SummaryReader::maxRepeatCounts` now excludes exact-duplicate groups (`distinct_hashes = 1` and `null_count = 0`) from the N+1 repeat count, keeping the table consistent with the headline.
- **Locate block labels critical routes correctly**: below-threshold repeat counts rendered as `N+1 x1` instead of `critical tier (p95 …)` because the reason keyed off `n1_repeat > 0`; it now keys off the repeat threshold.

## [1.4.0] - 2026-08-30

### Added

- **Exact duplicate query flagging**: queries are classified by binding values, not just SQL shape.
  - `bindings_hash` column stores a normalized MD5 of bound parameters (`1` and `'1'` match; empty bindings → null).
  - Repeated queries are now typed in reports: **CACHE** (same bindings — fix with `Cache::remember()`), **N+1** (varying bindings — fix with `with()`), or **unknown** (no binding data).
  - Drill-down shows cyan CACHE pills and red N+1 pills, plus `Cache::remember()` fix suggestions below the table.
  - Summary reports which routes contain duplicate queries; `pinpoint:check --json` exposes `query_type` for CI scripts.
- **Peak memory hydration tracking**: `peak_memory_kb` column records `memory_get_peak_usage(true)` at request flush.
  - Report table shows a Memory column (KB/MB), flagged bold-red when a route exceeds `pinpoint.memory_budget_kb` (default 20 MB; `PINPOINT_MEMORY_BUDGET_KB=10240` for a 10 MB cap, null disables).
  - Summary aggregates the maximum peak memory observed per route.

### Docs

- README: documented the Memory column, the `pinpoint.memory_budget_kb` budget flag, the CACHE vs N+1 drill-down badges, and the duplicate-query summary line.
- Verified against a real SPA/API app (FamilyTree): 12 MB route flagged red under a 10 MB budget, CACHE/duplicate detection working on live traffic.

## [1.3.2] - 2026-08-30

### Fixed

- **Table layout no longer distorts** (the "broken column/border" render): link placeholder tokens are now exactly as wide as their visible label. Previously the 19-char `__PINPOINT_LINK_n__` token sat inside Termwind table cells, so columns were sized around the token instead of the text — borders drawn over the route names. Tokens are now width-matched; labels too short to embed a unique index fall back to plain text.

## [1.3.1] - 2026-08-30

### Fixed

- **Editor jumps now open the right file**: `vscode://file` URIs now use the canonical documented form with exactly one slash before the path (`vscode://file/mnt/...`). The previous `vscode://file//mnt/...` (double slash) made URL parsers see an empty path — handlers fell back to the recently-focused file instead of the target (e.g. opening `AbstractRouteCollection.php`). Both route-name links and caller links affected; fixed for all editors.
- Custom editor schemes (`PINPOINT_EDITOR=devin`, `cursor`, `windsurf`, …) now actually pass through instead of silently becoming `vscode://`.

## [1.3.0] - 2026-08-30

### Added

- **Clickable route names**: every route in the summary table and Locate block now links to its controller action (`vscode://file/...` via controller reflection — `Class@method`, array-callable, invokable, and closure routes). Clicking a route name jumps straight to the handler instead of falling back to workspace search.

### Fixed

- `routeActionLocation()` refreshes route name lookups before resolving (Laravel 12 doesn't refresh them for runtime-registered routes).
- Route reflection failures degrade to a plain-text label — never breaks the report.

## [1.2.0] - 2026-08-30

### Fixed

- **Clickable caller links actually open the file again** (both fixes target the same symptom):
  - URIs now use **absolute paths** — `vscode://file/...` with a workspace-relative path made VS Code fall back to workspace search ("no matching results").
  - Link transport switched from hand-injected raw OSC 8 bytes to Symfony's canonical `<href=URI>text</>` tags — raw bytes corrupted surrounding glyphs in VS Code's xterm.js (`route(s)` → `ro te`, `Locate` → `ocate`). Termwind's native `<a href>` was confirmed to strip the URI entirely (dropping it silently) and was not used.
  - All HTML now balanced — `header()`'s outer `<div>` is closed by every renderer.
- Test captures now use **decorated output**, so the formatter path is exercised — the exact bug class that "all tests passed" while the terminal glitched.

### Added

- `pinpoint:report --json-to=FILE` and `pinpoint:check --json-to=FILE` — write the JSON payload to a file (auto-creating directories) and print the resolved path; `--json` (stdout) remains the CI pipe contract.
- Auto-enablement for `APP_ENV` in `local`, `development`, `dev`, `testing` — no `PINPOINT_ENABLED` needed; caller capture and the local API gate follow the same default. `PINPOINT_ENABLED=true/false` still overrides everywhere, staging/production stay opt-in.

## [1.1.0] - 2026-08-30

### Fixed

- VSCode OSC 8 links: path separators stay literal (`vscode://file/...`) — percent-encoded slashes broke editor jumps.
- Hyperlink tokens now reset between render units — no unbounded growth on long-lived renderer instances (Octane, test suites).
- `pinpoint:prune` deletes by parent request and relies on FK cascade instead of double-deleting child rows.
- `pinpoint:report --route`/suggestion/worst-caller queries now group both route-label branches in one `where` — safe to compose with other filters.
- Unknown `--tier` values fail loudly instead of silently rendering an empty table.
- Aggregation is transactional: a mid-batch failure rolls back everything instead of leaving a mixed snapshot.
- SQLite FK enforcement is now enabled in the test suite (`DB_FOREIGN_KEYS=true`), so the cascade behavior tested matches real deployments.

### Added

- `pinpoint:report --json` — machine-readable summary and route drill-down (`{meta, routes}` / `{route, queries, suggestions}`).
- `pinpoint:check --allow-empty` — explicit opt-out of the fail-closed empty-window gate.
- Summary line before the report table (`N route(s) · N critical · N with N+1`) and `--since` window shown in the header.
- Compound index `(request_id, sql_fingerprint)` on `pinpoint_queries` for the repeat-count aggregation.
- Route labels in the summary table capped at 40 columns.

### Changed

- `pinpoint:check` **fails closed** when the `--since` window is empty (a gate that checked nothing is a false green); use `--allow-empty` for legitimately empty runs.
- `CliRenderer` tier constants removed — single source of truth is `TierClassifier`.
- Caller links with a missing line render a placeholder instead of `file:0`.

## [1.0.0] - 2026-08-29

### Added

- Request/query collector: captures every DB query, total request time, query count, and query time.
- N+1 detection via two signals: Eloquent lazy-loading violations and repeated SQL fingerprint counts.
- `pinpoint:report` CLI: per-route tier summary (good → critical), `--route` drill-down with top queries and caller file:line, `--tier` filter, and `--since` for time-windowed views.
- Actionable code suggestions: lazy-load violations are persisted and chained (`Model::with('stages.photos')`) with the caller location.
- `pinpoint:check` CI gate: fail on N+1s or query/duration budgets, with `--json` output for PR-comment automation.
- `pinpoint:aggregate` command for offline percentile computation, `pinpoint:prune` for retention, and `pinpoint:reset` to clear recorded data.
- Versioned local read API at `/_pinpoint/api/v1/` (summaries + per-route queries), gated to local/debug environments.
- Termwind-rendered CLI output with tier pills, right-aligned metrics, and N+1 emphasis.
- Clickable IDE links: every caller `file:line` is an OSC 8 terminal hyperlink (VS Code default, PhpStorm via `pinpoint.editor`), and the summary prints a "Locate" block for the top 5 worst offenders.
- Caller capture can be force-enabled on staging with `PINPOINT_CAPTURE_CALLER=true`.

### Changed

- Caller capture only in local/testing environments, with an opt-out flag.
- Request flush deferred until after the response is sent.
- SQL fingerprints use md5 (collision-safe), summary computation is memory-bounded, and migrations publish with current timestamps.
- Route summaries fall back to `METHOD path` for unnamed routes.

### Fixed

- Queue workers no longer leak buffered queries (scoped lifecycle).
- Stale samples can no longer skew tiers (use `--since` or `pinpoint:reset`).
- Caller file:line now points at app code, not vendor internals.
- CI matrix no longer tests an impossible PHP 8.2 × Laravel 13 combination (Laravel 13 requires PHP ^8.3).
- `pinpoint_summaries.route_name` is unique, so `pinpoint:aggregate` is a true upsert.

### Security

- Parameterized SQL is stored without bound values; unparameterized literals are documented as a caveat.
## [1.4.2] - 2026-08-31

### Added

- **Composite Health column (opt-in)**: `PINPOINT_COMPOSITE_TIER=true` replaces the p95-only tier column with a single verdict — `HEALTHY` only when the p95 tier is good/acceptable AND no N+1 AND peak memory is within budget; otherwise `NEEDS WORK (GOOD)`-style with the latency tier kept in parentheses. The header (`Health (tier + N+1 + memory)`) states what it measures, and `--json` rows gain `health` + `health_reason` fields. Off by default — the standard tier column is unchanged.

### Changed

- Report table headers now state their reference: `Tier (p95 only)` and `Memory (peak)` — a GOOD tier no longer reads as endorsing the whole route when it carries an N+1 or memory flag.

## [1.4.1] - 2026-08-31

### Fixed

- **Drill-down and suggestions now respect `--since`**: `pinpoint:report --route=X --since=1h` previously read ALL recorded requests for the route, so stale pre-fix N+1 rows kept appearing after a fix. Both the drill-down table and eager-load suggestion chains are now windowed by `created_at`.
