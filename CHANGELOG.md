# Changelog

All notable changes to Pinpoint will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Phase 1: request/query collector, N+1 detection (lazy-loading violations + fingerprint repeats), `pinpoint_requests`/`pinpoint_queries` tables.
- Phase 2: `pinpoint:aggregate` command rolling raw requests into per-route p50/p95/p99 summaries with tiers.
- Phase 3: `pinpoint:report` CLI with `--tier` filter and `--route` drill-down showing top queries with caller file:line.
- Phase 4: versioned local read API at `/_pinpoint/api/v1/` (`summaries` + `summaries/{route}/queries`), gated by `LocalOnly` middleware (local or debug mode, config-gated).
- `pinpoint:prune` command with `pinpoint.retention_days` config (default 30).
- CI matrix (PHP 8.3–8.5, Laravel 12–13, Windows + Ubuntu, prefer-lowest/prefer-stable), Pest test suite, Pint + Larastan tooling.
- Overhead benchmark harness (`composer benchmark`) with published numbers in the README.

### Changed

- Caller capture (`debug_backtrace`) now runs only in local environments.
- Route summaries fall back to `METHOD path` when `route_name` is null instead of collapsing into one bucket.
- `Pinpoint` facade added; `Pinpoint::observeLazyLoad()` is now a real static call (was instance-only despite docs).
- Lazy-loading observer is gated by the `pinpoint.enabled` master switch and no longer overrides the host app's strict-mode choice in production.
- `Recorder`/`Pinpoint` bound as `scoped()` instead of `singleton()` — safe under Octane.
- Summaries and drill-downs compute from a single pass over the requests table instead of re-scanning per route.
- Caller capture excludes `vendor/` frames so file:line points at app code, not the package.
- `pinpoint:prune` validates the retention window (`--days=0` or garbage no longer deletes everything).
- Route threshold overrides merge with defaults, so partial overrides no longer crash.
- `pinpoint_queries.request_id` gets an explicit index for SQLite/Postgres.
- Drill-down API accepts slash-containing route labels (`->where('route', '.*')`).
- Composer deps slimmed to `laravel/framework` + `spatie/laravel-package-tools`; CI matrix extended to PHP 8.2.
- `.gitattributes` added so tests/docs/benchmarks don't ship inside the package archive.