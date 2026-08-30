# Pinpoint

[![run-tests](https://github.com/asim-ali-peerzada/pinpoint/actions/workflows/run-tests.yml/badge.svg)](https://github.com/asim-ali-peerzada/pinpoint/actions/workflows/run-tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/asimali/pinpoint.svg?style=flat-square)](https://packagist.org/packages/asimali/pinpoint)

**[Explore the Documentation & Features ↗](https://asim-ali-peerzada.github.io/pinpoint)**

Pinpoint is a local-first Laravel request performance profiler. It captures every DB query during a request, detects N+1 patterns, tiers each route (good → critical), and gives you a CLI report that drills from a slow endpoint straight to the offending query and its `caller file:line`.

![Pinpoint CLI performance report](docs/terminal-cli.png)

Terminal output is rendered with **Termwind** (ships with Laravel): tier pills are color-coded (green / yellow / red), numbers are right-aligned for quick scanning, units are dimmed, and N+1 flags are red and bold. The design is defined once in `Internal\CliRenderer` and shared by every Pinpoint command.

## Scope & Limitations

- **Is:** a local/dev + limited-staging diagnostics tool for request time, query count, query time, and N+1 detection.
- **Is not:** an APM replacement, a production-wide trace collector, or a memory/CPU profiler (use Blackfire for those).

## Installation

```bash
composer require asimali/pinpoint
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag=pinpoint-migrations
php artisan migrate
```

Optionally publish the config to customize it:

```bash
php artisan vendor:publish --tag=pinpoint-config
```

## Usage

Pinpoint starts recording automatically when enabled. It's **enabled by default when `APP_ENV` is `local`, `development`, `dev`, or `testing`** — zero config needed. `PINPOINT_ENABLED=false` hard-disables it everywhere (e.g. production); `PINPOINT_ENABLED=true` is only needed for non-standard environments like `staging`. Caller file:line capture (`debug_backtrace`) follows the same default in local/dev/testing and can be turned on/off with `PINPOINT_CAPTURE_CALLER`.

## Command reference

| Command | What it does | Options | Exit codes |
|---|---|---|---|
| `pinpoint:report` | Per-route summary (p50/p95/p99/avg, tier, N+1) + a "Locate" block for the worst offenders | `--tier=`, `--route=`, `--since=`, `--limit=`, `--json`, `--json-to=` | `0` normal, `1` invalid input / DB error |
| `pinpoint:check` | CI gate: fail the build on N+1s or query/duration budget violations | `--fail-on-n1`, `--max-queries=`, `--max-duration-ms=`, `--since=`, `--allow-empty`, `--json`, `--json-to=`, `--limit=` | `0` pass, `1` fail |
| `pinpoint:aggregate` | Roll recent raw requests into the `pinpoint_summaries` table (offline percentiles, all-or-nothing per run) | — | `0` success, `1` failure |
| `pinpoint:prune` | Delete recorded data older than the retention window (`pinpoint.retention_days`, default 30) | `--days=` | `0` success, `1` failure |
| `pinpoint:reset` | Wipe ALL recorded data (requests, queries, lazy loads, summaries) | `--force` (skip the confirmation prompt) | `0` success, `1` failure |

Local read API (local/debug environments only — blocked by the `LocalOnly` middleware otherwise):

| Endpoint | Returns |
|---|---|
| `GET /_pinpoint/api/v1/summaries` | Per-route tiers as JSON |
| `GET /_pinpoint/api/v1/summaries/{route}/queries` | Top offending queries for one route (URL-encode the route name; `METHOD path` labels work too) |

### Try it on your project

```bash
# 1. Start clean
php artisan pinpoint:reset --force

# 2. Exercise the app — hit endpoints in the browser/curl, or run your test suite
curl http://localhost:8000/api/orders

# 3. Inspect what was recorded
php artisan pinpoint:report                              # summary table
php artisan pinpoint:report --since=1h                   # only the last hour
php artisan pinpoint:report --tier=critical              # only critical routes
php artisan pinpoint:report --route=api.orders           # drill into a route
php artisan pinpoint:report --limit=5                    # top 5 routes only
php artisan pinpoint:report --json | jq .                # machine-readable summary
php artisan pinpoint:report --json-to=storage/pinpoint/report.json  # same JSON to a file, prints its path

# 4. Run the CI gate locally (exit 1 = violation)
php artisan pinpoint:check --fail-on-n1 --max-queries=20 --max-duration-ms=1000
php artisan pinpoint:check --json                        # JSON for scripts

# 5. Roll raw rows into summaries (schedule this) and prune old data
php artisan pinpoint:aggregate
php artisan pinpoint:prune --days=7
```

### The report

```bash
php artisan pinpoint:report                      # summary table for all routes
php artisan pinpoint:report --tier=critical      # only critical routes
php artisan pinpoint:report --route=api.orders   # drill into a route: top queries + caller file:line
php artisan pinpoint:report --since=1h           # only consider recent samples
php artisan pinpoint:report --since=5m           # ...or just the last 5 minutes
php artisan pinpoint:report --limit=10           # cap the table (default 20)
php artisan pinpoint:report --json               # machine-readable summary (scripts / webhooks)
php artisan pinpoint:report --json-to=storage/pinpoint/report.json   # same JSON written to a file, path printed
```

**Iterating on a fix?** The report reads **historical samples** — after you fix an N+1 or a slow route, the old pre-fix rows still skew the tiers until they age out of the window or are pruned. `--since` accepts any natural duration (`5`, `5m`, `5min`, `1h`, `2d`; bare number = minutes), so you see your fix's effect immediately:

```bash
php artisan pinpoint:report --since=5m           # post-fix verification, minutes later
php artisan pinpoint:reset                       # or: clear all recorded data entirely
```

`pinpoint:reset` wipes every recorded request/query/lazy-load/summary (asks for confirmation; use `--force` in scripts).

### Aggregation (staging/production)

At scale, compute percentiles offline instead of per-request:

```php
// app/Console/Kernel.php
$schedule->command('pinpoint:aggregate')->hourly();
```

```bash
php artisan pinpoint:report --route=api.packages
```

Drilling into a route with lazy-load violations also prints **actionable fixes**, not just warnings:

```text
N+1 detected — suggested eager loads:
  App\Models\CloseoutPackage -> stages.photos at app/Services/ApprovalReadinessService.php:124
  Suggested fix: App\Models\CloseoutPackage::with('stages.photos')
```

**Click to jump to the exact line:** every `file:line` in the report is an OSC 8 terminal hyperlink. Route names in the summary table and the Locate block are links too — Pinpoint resolves each route to its controller action via reflection, so ⌘-clicking a route name jumps straight to the handler method. ⌘-click (macOS) / Ctrl-click (Windows/Linux) works from inside Docker/Sail/WSL because the host terminal resolves the URI scheme, not the container. Default is VS Code (`vscode://file/path:line`); switch to PhpStorm via config:

```php
// config/pinpoint.php
'editor' => 'phpstorm', // or env PINPOINT_EDITOR=phpstorm
```

Any other editor that registers its own URI scheme works too — set `PINPOINT_EDITOR` to its scheme and Pinpoint emits `<scheme>://file/path:line` (e.g. `devin`, `cursor`, `windsurf`). VS Code-compatible forks (Cursor, Windsurf/Devin Desktop) typically register their scheme (or `vscode://`) as the handler; pick whichever your OS opens by default.

The summary table is followed by a **Locate** block showing the top 5 worst offenders (N+1 or critical routes) with their caller line; the rest are listed with a hint to drill in.

Pinpoint persists the model + relation of every lazy-loading violation, chains nested relations (`stages.photos` when `stages` itself is lazily loaded), and shows the exact caller — so the fix is copy-paste ready.

### CI / GitHub Actions — fail the merge on N+1s and query bloat

Pinpoint doubles as a regression gate: run your test suite (requests get recorded), then `pinpoint:check` fails the job when a PR introduces an N+1 or blows a query/duration budget — with the exact offending SQL and `file:line` in the output.

```bash
php artisan pinpoint:check --fail-on-n1 --max-queries=20 --max-duration-ms=1000
```

Exit code is `0` (pass) or `1` (fail) — drop it straight into a workflow:

```yaml
steps:
  - run: php artisan test
  - run: php artisan pinpoint:check --fail-on-n1 --max-queries=20 --json > pinpoint-report.json
  - if: failure()
    uses: actions/github-script@v7
    with:
      script: |
        const fs = require('fs');
        const report = JSON.parse(fs.readFileSync('pinpoint-report.json', 'utf8'));
        core.setFailed(report.violations.map(v =>
          `N+1 in ${v.route}: ${v.sql} at ${v.caller_file}:${v.caller_line} (x${v.repeat_count})`
        ).join('\n'));
```

Options:

- `--fail-on-n1` — fail when any request repeats the same SQL ≥ `pinpoint.n_plus_one_repeat_threshold` times (Eloquent lazy loads are included via the violation callback).
- `--max-queries=N` — fail when any request exceeds N queries.
- `--max-duration-ms=N` — fail when any request exceeds N ms.
- `--since=MINUTES` (default 30) — only inspect recent requests; stale rows from previous runs can't false-fail a PR.
- `--allow-empty` — pass (with a warning) when the window contains no requests.
- `--json` — machine-readable `{passed, meta, violations[]}` for PR-comment automation.

Notes for CI:

- **Callers are captured in `testing` and `local` environments** — in CI, run tests with `APP_ENV=testing` so the report includes the exact file:line.
- The check reads raw tables, so it's meant for the **same job that just ran the tests** (fresh data, `--since` guards the window). Use `sample_rate = 1.0` in the CI environment so the gate is deterministic — the command warns if sampling is on.
- **No data in the window → the gate fails closed** (a check that evaluated nothing is a false green). If an empty run is legitimate for your pipeline, add `--allow-empty`.

## Retention

Raw tables grow. Prune old data on a schedule (default retention: 30 days, configurable via `pinpoint.retention_days`):

```php
$schedule->command('pinpoint:prune')->daily();
```

## N+1 detection — how it works and its limits

Two signals:

1. **Lazy-loading violations (semantic):** Pinpoint registers a violation handler that records the model + relation. This is precise but only covers Eloquent relations, and it **chains to any handler already registered before Pinpoint boots**. If your app registers its own `handleLazyLoadingViolationUsing()` in a provider that boots *after* Pinpoint, it will overwrite Pinpoint's handler — in that case, call `Pinpoint::observeLazyLoad($model, $relation)` inside your own handler to keep the signal working. You can disable this signal entirely with `pinpoint.capture_lazy_loading_violations = false`.
2. **Fingerprint repeat count (heuristic):** the same normalized SQL appearing 3+ times (`pinpoint.n_plus_one_repeat_threshold`) in one request is flagged. This catches N+1s done via the raw query builder, but it's a heuristic — a legitimate loop that intentionally runs the same query 3+ times will be flagged. Treat this signal as *likely* N+1, not proof.

Both signals set `has_n_plus_one`; the report shows the repeat count as `Yes (xN)`.

## Performance

Measured with `composer benchmark` (in-memory SQLite, 10 queries/request, 200 requests, Testbench skeleton app). DB writes are deferred to the application's `terminating` callbacks — after the response is sent — so the request path only pays for in-memory capture:

| Scenario | Mean request time | Overhead |
|---|---|---|
| Pinpoint disabled | ~0.84 ms | — |
| Enabled, no caller capture | ~1.13 ms | ~0.29 ms |
| Enabled, local + caller capture | ~1.13 ms | ~0.29 ms |

The worst case (caller capture via `debug_backtrace`) only runs in local environments — production never pays it. The remaining overhead is one fingerprint hash per query plus the deferred request/query row inserts. Re-run on your own hardware: `composer benchmark`.

## Production guidance

- **Recommended use:** local development, and staging at `sample_rate` 0.1–0.2.
- **Do not** run `sample_rate = 1.0` at high scale — every request inserts rows.
- **Caller capture** (`debug_backtrace`, the most expensive part) only runs in local environments. Disable it everywhere with `pinpoint.capture_caller = false`.
- **Retention:** schedule `pinpoint:prune` (default 30 days) or raw tables grow unbounded.
- **Route grouping:** requests are grouped by `route_name`; requests without a route name fall back to `METHOD path`. If many endpoints share a route name, the summary flattens them — name your routes for useful grouping.
- **Stored SQL is parameterized** — bound values are never persisted (Laravel sends them as `?` placeholders, and Pinpoint stores only the SQL string). The exception is *unparameterized* SQL (`DB::select("... where x = '$value'")`, `whereRaw` with interpolated values): those literals are stored verbatim, so don't pass secrets through string interpolation into raw queries.
- **Local summary computation is in-memory** — `pinpoint:report` and the API read all raw rows to compute percentiles on demand. This is instant at local-dev volumes; for large staging/prod datasets use `pinpoint:aggregate` on a schedule and keep retention tight.

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for recent changes.

## Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Report security vulnerabilities privately to `asimalipeerzada@gmail.com`. Please do not open a public issue.

## Credits

- [Asim Ali](https://github.com/asim-ali-peerzada)

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.