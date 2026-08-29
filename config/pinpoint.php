<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | Enabled in local by default. Recommended: local development only,
    | or limited sampling in staging (see sample_rate below).
    */
    'enabled' => env('PINPOINT_ENABLED', app()->environment('local')),

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
    | debug_backtrace is the most expensive thing Pinpoint does. It only
    | runs in local environments regardless of this flag; set to false to
    | disable it locally too.
    */
    'capture_caller' => env('PINPOINT_CAPTURE_CALLER', true),

    'capture_lazy_loading_violations' => true,

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
