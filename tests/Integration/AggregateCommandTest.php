<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::table('pinpoint_requests')->truncate();
    DB::table('pinpoint_summaries')->truncate();
});

test('aggregate rolls requests into percentile summaries', function () {
    DB::table('pinpoint_requests')->insert([
        ['route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders', 'duration_ms' => 100, 'query_count' => 1, 'query_time_ms' => 10, 'has_n_plus_one' => false, 'created_at' => now()],
        ['route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders', 'duration_ms' => 200, 'query_count' => 1, 'query_time_ms' => 10, 'has_n_plus_one' => false, 'created_at' => now()],
        ['route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders', 'duration_ms' => 300, 'query_count' => 1, 'query_time_ms' => 10, 'has_n_plus_one' => false, 'created_at' => now()],
        ['route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders', 'duration_ms' => 9000, 'query_count' => 1, 'query_time_ms' => 10, 'has_n_plus_one' => false, 'created_at' => now()],
    ]);

    $this->artisan('pinpoint:aggregate')->assertSuccessful();

    $this->assertDatabaseHas('pinpoint_summaries', [
        'route_name' => 'api.orders',
        'sample_count' => 4,
        'p50_ms' => 200,
        'p95_ms' => 9000,
        'p99_ms' => 9000,
        'avg_ms' => 2400,
        'tier' => 'critical',
    ]);
});

test('aggregate classifies p95 against route threshold overrides', function () {
    config()->set('pinpoint.route_threshold_overrides', [
        'api.orders' => ['good' => 10000, 'acceptable' => 20000, 'needs_improvement' => 30000],
    ]);

    DB::table('pinpoint_requests')->insert([
        ['route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders', 'duration_ms' => 9000, 'query_count' => 1, 'query_time_ms' => 10, 'has_n_plus_one' => false, 'created_at' => now()],
    ]);

    $this->artisan('pinpoint:aggregate')->assertSuccessful();

    $this->assertDatabaseHas('pinpoint_summaries', [
        'route_name' => 'api.orders',
        'tier' => 'good',
    ]);
});

test('aggregate updates existing summaries instead of duplicating', function () {
    DB::table('pinpoint_requests')->insert([
        ['route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders', 'duration_ms' => 100, 'query_count' => 1, 'query_time_ms' => 10, 'has_n_plus_one' => false, 'created_at' => now()],
    ]);

    $this->artisan('pinpoint:aggregate')->assertSuccessful();
    $this->artisan('pinpoint:aggregate')->assertSuccessful();

    expect(DB::table('pinpoint_summaries')->count())->toBe(1);
});

test('aggregate handles missing tables without breaking', function () {
    DB::table('pinpoint_requests')->insert([
        ['route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders', 'duration_ms' => 100, 'query_count' => 1, 'query_time_ms' => 10, 'has_n_plus_one' => false, 'created_at' => now()],
    ]);

    Schema::drop('pinpoint_summaries');

    $this->artisan('pinpoint:aggregate')->assertFailed();
});
