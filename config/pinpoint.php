<?php

return [
    'enabled' => env('PINPOINT_ENABLED', app()->environment('local')),

    'sample_rate' => 1.0,

    'thresholds_ms' => ['good' => 150, 'acceptable' => 400, 'needs_improvement' => 1000],

    'route_threshold_overrides' => [
        // 'api.search.autocomplete' => ['good' => 50, 'acceptable' => 120, 'needs_improvement' => 300],
        // 'api.reports.export' => ['good' => 1000, 'acceptable' => 3000, 'needs_improvement' => 8000],
    ],

    'n_plus_one_repeat_threshold' => 3,

    'capture_caller' => env('PINPOINT_CAPTURE_CALLER', true),

    'capture_lazy_loading_violations' => true,
];
