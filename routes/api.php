<?php

use AsimAli\Pinpoint\Http\Controllers\PinpointApiController;
use AsimAli\Pinpoint\Http\Middleware\LocalOnly;
use Illuminate\Support\Facades\Route;

Route::middleware(LocalOnly::class)
    ->prefix('_pinpoint/api/v1')
    ->group(function () {
        Route::get('summaries', [PinpointApiController::class, 'summaries']);
        Route::get('summaries/{route}/queries', [PinpointApiController::class, 'topQueries']);
    });
