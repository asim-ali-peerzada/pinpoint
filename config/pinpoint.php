<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | Enabled by default in local/development/testing environments.
    | Recommended: local development only, or limited sampling in staging.
    */
    'enabled' => env('PINPOINT_ENABLED', in_array(env('APP_ENV', 'local'), ['local', 'development', 'dev', 'testing'], true)),

    /*
    |--------------------------------------------------------------------------
    | Sampling
    |--------------------------------------------------------------------------
    |
    | 1.0 records every request. In staging/production use 0.1–0.2 unless
    | you know what you're doing — every recorded request inserts rows.
    */
    'sample_rate' => 1.0,

    'thresholds_ms' => ['good' => 150, 'acceptable' => 400, 'needs_improvement' => 1000],

    'route_threshold_overrides' => [
        // 'api.search.autocomplete' => ['good' => 50, 'acceptable' => 120, 'needs_improvement' => 300],
        // 'api.reports.export' => ['good' => 1000, 'acceptable' => 3000, 'needs_improvement' => 8000],
    ],

    'n_plus_one_repeat_threshold' => 3,

    /*
    |--------------------------------------------------------------------------
    | Memory budget
    |--------------------------------------------------------------------------
    |
    | Peak RAM (in KB) beyond which a route is flagged in the report table.
    | The Memory column turns red when a route's recorded peak_memory_kb
    | exceeds this value. Default: 20 MB (20 × 1024 KB).
    | Set to null to disable the budget check (column still shows the value).
    */
    'memory_budget_kb' => env('PINPOINT_MEMORY_BUDGET_KB', 20 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Caller capture
    |--------------------------------------------------------------------------
    |
    | debug_backtrace is the most expensive thing Pinpoint does. It runs by
    | default in local and testing (CI) environments. To capture callers on
    | staging, set PINPOINT_CAPTURE_CALLER=true explicitly; set it to false
    | to disable even in local.
    */
    'capture_caller' => env('PINPOINT_CAPTURE_CALLER'),

    'capture_lazy_loading_violations' => true,

    /*
    |--------------------------------------------------------------------------
    | Editor hyperlinks
    |--------------------------------------------------------------------------
    |
    | URI scheme used for clickable file:line links in CLI output (OSC 8
    | hyperlinks — works from inside Docker/Sail/WSL because the host
    | terminal resolves the scheme, not the container).
    | Supported: vscode, phpstorm. Custom schemes allowed.
    */
    'editor' => env('PINPOINT_EDITOR', 'vscode'),

    /*
    |--------------------------------------------------------------------------
    | Composite health column
    |--------------------------------------------------------------------------
    |
    | When true, the report's Tier column becomes a composite "Health"
    | verdict: HEALTHY only when the p95 tier is good/acceptable AND no N+1
    | AND peak memory is within budget. Otherwise NEEDS WORK (with the p95
    | tier in parentheses). Off by default — the standard Tier (p95 only)
    | column is unchanged.
    */
    'composite_tier' => env('PINPOINT_COMPOSITE_TIER', false),

    /*
    |--------------------------------------------------------------------------
    | Regression diff thresholds
    |--------------------------------------------------------------------------
    |
    | pinpoint:diff flags a regression when the change EXCEEDS these thresholds.
    | Set to null to disable a specific check.
    |
    | regression_duration_pct — percentage increase in p95 latency (e.g. 20 = flag at +20%)
    | regression_query_count  — absolute increase in N+1 repeat count (e.g. 3 = flag at +3 more)
    | regression_memory_pct   — percentage increase in peak_memory_kb (e.g. 50 = flag at +50%)
    */
    'diff' => [
        'regression_duration_pct' => env('PINPOINT_DIFF_DURATION_PCT', 20),
        'regression_query_count' => env('PINPOINT_DIFF_QUERY_COUNT', 3),
        'regression_memory_pct' => env('PINPOINT_DIFF_MEMORY_PCT', 50),
        // Minimum recorded samples on BOTH sides before a route is judged.
        // Default 1 compares single samples (the snapshot → one-request →
        // diff loop); real CI gates should raise this (e.g. 10) so a lone
        // noisy request can't flag a route.
        'min_samples' => env('PINPOINT_DIFF_MIN_SAMPLES', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Raw tables grow unbounded if never pruned. Schedule `pinpoint:prune`
    | (e.g. daily) to delete data older than this window.
    */
    'retention_days' => 30,
];
