# Changelog

All notable changes to Pinpoint will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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