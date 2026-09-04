<?php

use Illuminate\Support\Facades\DB;

/**
 * Route identity resolution: verifies telemetry aggregation groupings:
 * - Named routes aggregate under their route name.
 * - Unnamed routes aggregate by 'METHOD path'.
 */
function seedRouteRequest(?string $routeName, string $method, string $path, int $durationMs): void
{
    DB::table('pinpoint_requests')->insert([
        'route_name' => $routeName,
        'method' => $method,
        'path' => $path,
        'duration_ms' => $durationMs,
        'query_count' => 1,
        'query_time_ms' => 1,
        'has_n_plus_one' => false,
        'peak_memory_kb' => 2048,
        'created_at' => now(),
    ]);
}

beforeEach(function () {
    DB::table('pinpoint_requests')->truncate();
    DB::table('pinpoint_queries')->truncate();
    DB::table('pinpoint_lazy_loads')->truncate();
    DB::table('pinpoint_summaries')->truncate();
});

test('named routes aggregate across different paths into one row', function () {
    seedRouteRequest('families.show', 'GET', 'api/families/1', 100);
    seedRouteRequest('families.show', 'GET', 'api/families/2', 300);
    seedRouteRequest('families.show', 'GET', 'api/families/3', 500);

    $output = runArtisanCaptured('pinpoint:report');

    expect($output)->toContain('families.show')
        ->not->toContain('families/1')
        ->not->toContain('families/2')
        ->not->toContain('families/3');
});

test('HTTP method is part of the route identity', function () {
    seedRouteRequest(null, 'GET', 'api/orders', 100);
    seedRouteRequest(null, 'POST', 'api/orders', 200);

    $output = runArtisanCaptured('pinpoint:report');

    expect($output)
        ->toContain('GET /api/orders')
        ->toContain('POST /api/orders');
});

test('unnamed routes keep distinct metrics per method and path', function () {
    seedRouteRequest(null, 'GET', 'api/orders', 100);
    seedRouteRequest(null, 'GET', 'api/customers', 200);

    $output = runArtisanCaptured('pinpoint:report');

    expect($output)
        ->toContain('GET /api/orders')
        ->toContain('GET /api/customers');
});

test('parameterized unnamed routes produce per-value labels', function () {
    // When no route name is assigned, the raw path forms the identity,
    // keeping distinct parameter values separated.
    seedRouteRequest(null, 'GET', 'api/families/1', 100);
    seedRouteRequest(null, 'GET', 'api/families/2', 300);

    $output = runArtisanCaptured('pinpoint:report');

    expect($output)->toContain('GET /api/families/1')
        ->toContain('GET /api/families/2');
});
