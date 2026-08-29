<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::table('pinpoint_requests')->truncate();
    DB::table('pinpoint_queries')->truncate();
});

test('api summaries returns per-route tiers as json', function () {
    DB::table('pinpoint_requests')->insert([
        ['route_name' => 'api.fast', 'method' => 'GET', 'path' => 'api/fast', 'duration_ms' => 10, 'query_count' => 1, 'query_time_ms' => 1, 'has_n_plus_one' => false, 'created_at' => now()],
        ['route_name' => 'api.slow', 'method' => 'GET', 'path' => 'api/slow', 'duration_ms' => 5000, 'query_count' => 1, 'query_time_ms' => 1, 'has_n_plus_one' => false, 'created_at' => now()],
    ]);

    $this->getJson('/_pinpoint/api/v1/summaries')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.route', 'api.slow')
        ->assertJsonPath('data.0.tier', 'critical')
        ->assertJsonPath('data.1.tier', 'good');
});

test('api top queries returns offending queries for a route', function () {
    $id = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders',
        'duration_ms' => 5000, 'query_count' => 2, 'query_time_ms' => 100,
        'has_n_plus_one' => true, 'created_at' => now(),
    ]);

    DB::table('pinpoint_queries')->insert([
        ['request_id' => $id, 'sql_fingerprint' => 'abc', 'sql' => 'select * from orders where user_id = ?', 'time_ms' => 99, 'caller_file' => 'app/Models/Order.php', 'caller_line' => 12, 'created_at' => now()],
    ]);

    $this->getJson('/_pinpoint/api/v1/summaries/api.orders/queries')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.sql', 'select * from orders where user_id = ?')
        ->assertJsonPath('data.0.caller_file', 'app/Models/Order.php')
        ->assertJsonPath('data.0.caller_line', 12);
});

test('api blocks when pinpoint is disabled', function () {
    config()->set('pinpoint.enabled', false);

    $this->getJson('/_pinpoint/api/v1/summaries')->assertNotFound();
});
