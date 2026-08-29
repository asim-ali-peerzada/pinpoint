# Changelog

All notable changes to Pinpoint will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Phase 1: request/query collector, N+1 detection (lazy-loading violations + fingerprint repeats), `sentinel_requests`/`sentinel_queries` tables.
- Phase 2: `pinpoint:aggregate` command rolling raw requests into per-route p50/p95/p99 summaries with tiers.
- Phase 3: `pinpoint:report` CLI with `--tier` filter and `--route` drill-down showing top queries with caller file:line.
- `pinpoint:prune` command with `pinpoint.retention_days` config (default 30).
- CI matrix (PHP 8.3–8.5, Laravel 12–13, Windows + Ubuntu, prefer-lowest/prefer-stable), Pest test suite, Pint + Larastan tooling.

### Changed

- Caller capture (`debug_backtrace`) now runs only in local environments.
- Route summaries fall back to `METHOD path` when `route_name` is null instead of collapsing into one bucket.