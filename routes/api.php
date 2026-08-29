<?php

use AsimAli\Pinpoint\Http\Controllers\PinpointApiController;
use AsimAli\Pinpoint\Http\Middleware\LocalOnly;
use Illuminate\Support\Facades\Route;

Route::middleware(LocalOnly::class)
    ->prefix('_pinpoint/api/v1')
    ->group(function () {
        Route::get('summaries', [PinpointApiController::class, 'summaries']);
        // Labels may be "METHOD path" (contains a slash) — allow slashes in
        // the segment so unnamed-route drill-downs actually resolve.
        Route::get('summaries/{route}/queries', [PinpointApiController::class, 'topQueries'])
            ->where('route', '.*');
    });
