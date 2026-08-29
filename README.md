# Pinpoint

[![run-tests](https://github.com/asim-ali-peerzada/pinpoint/actions/workflows/run-tests.yml/badge.svg)](https://github.com/asim-ali-peerzada/pinpoint/actions/workflows/run-tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/asimali/pinpoint.svg?style=flat-square)](https://packagist.org/packages/asimali/pinpoint)
[![Total Downloads](https://img.shields.io/packagist/dt/asimali/pinpoint.svg?style=flat-square)](https://packagist.org/packages/asimali/pinpoint)

Pinpoint is a local-first Laravel request performance profiler. It captures every DB query during a request, detects N+1 patterns, tiers each route (good → critical), and gives you a CLI report that drills from a slow endpoint straight to the offending query and its `caller file:line`.

```bash
php artisan pinpoint:report

┌─────────────┬────────┬────────┬─────────┬──────────┬──────────┐
│ Route       │ p95    │ Avg    │ Samples │ Tier     │ N+1?     │
├─────────────┼────────┼────────┼─────────┼──────────┼──────────┤
│ api.orders  │ 1420ms │ 890ms  │ 340     │ CRITICAL │ Yes (x14)│
│ api.users   │ 210ms  │ 140ms  │ 1200    │ ACCEPT.  │ No       │
│ api.ping    │ 95ms   │ 60ms   │ 800     │ GOOD     │ No       │
└─────────────┴────────┴────────┴─────────┴──────────┴──────────┘
```

## What this is / what it is not

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

Pinpoint starts recording automatically when enabled. It's **enabled in local by default** (`PINPOINT_ENABLED` env, or `pinpoint.enabled` config) and captures caller file:line only when the app is local.

### The report

```bash
php artisan pinpoint:report                      # summary table for all routes
php artisan pinpoint:report --tier=critical      # only critical routes
php artisan pinpoint:report --route=api.orders   # drill into a route: top queries + caller file:line
```

### Aggregation (staging/production)

At scale, compute percentiles offline instead of per-request:

```php
// app/Console/Kernel.php
$schedule->command('pinpoint:aggregate')->hourly();
```

### Retention

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

Measured with `composer benchmark` (in-memory SQLite, 10 queries/request, 200 requests, Testbench skeleton app):

| Scenario | Mean request time | Overhead |
|---|---|---|
| Pinpoint disabled | ~1.0 ms | — |
| Enabled, no caller capture | ~1.8 ms | ~0.9 ms |
| Enabled, local + caller capture | ~2.4 ms | ~1.5 ms |

The worst case (caller capture via `debug_backtrace`) only runs in local environments — production never pays it. The remaining overhead is one fingerprint hash per query plus the final request/query row inserts. Re-run on your own hardware: `composer benchmark`.

## Production guidance

- **Recommended use:** local development, and staging at `sample_rate` 0.1–0.2.
- **Do not** run `sample_rate = 1.0` at high scale — every request inserts rows.
- **Caller capture** (`debug_backtrace`, the most expensive part) only runs in local environments. Disable it everywhere with `pinpoint.capture_caller = false`.
- **Retention:** schedule `pinpoint:prune` (default 30 days) or raw tables grow unbounded.
- **Route grouping:** requests are grouped by `route_name`; requests without a route name fall back to `METHOD path`. If many endpoints share a route name, the summary flattens them — name your routes for useful grouping.

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

The MIT License (MIT). Please see [LICENSE](LICENSE.md) for more information.