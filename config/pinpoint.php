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
    | Retention
    |--------------------------------------------------------------------------
    |
    | Raw tables grow unbounded if never pruned. Schedule `pinpoint:prune`
    | (e.g. daily) to delete data older than this window.
    */
    'retention_days' => 30,
];
