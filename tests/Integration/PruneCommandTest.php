<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::table('pinpoint_requests')->truncate();
    DB::table('pinpoint_queries')->truncate();
    DB::table('pinpoint_summaries')->truncate();
});

test('prune deletes requests and queries older than the retention window', function () {
    $old = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.old', 'method' => 'GET', 'path' => 'api/old',
        'duration_ms' => 100, 'query_count' => 1, 'query_time_ms' => 10,
        'has_n_plus_one' => false, 'created_at' => now()->subDays(60),
    ]);
    $new = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.new', 'method' => 'GET', 'path' => 'api/new',
        'duration_ms' => 100, 'query_count' => 1, 'query_time_ms' => 10,
        'has_n_plus_one' => false, 'created_at' => now(),
    ]);

    DB::table('pinpoint_queries')->insert([
        ['request_id' => $old, 'sql_fingerprint' => 'a', 'sql' => 'select 1', 'time_ms' => 1, 'caller_file' => null, 'caller_line' => null, 'created_at' => now()->subDays(60)],
        ['request_id' => $new, 'sql_fingerprint' => 'b', 'sql' => 'select 2', 'time_ms' => 1, 'caller_file' => null, 'caller_line' => null, 'created_at' => now()],
    ]);

    $this->artisan('pinpoint:prune')->assertSuccessful();

    $this->assertDatabaseMissing('pinpoint_requests', ['id' => $old]);
    $this->assertDatabaseHas('pinpoint_requests', ['id' => $new]);
    // Children are removed by the FK cascade, not a direct delete.
    $this->assertDatabaseMissing('pinpoint_queries', ['request_id' => $old]);
    $this->assertDatabaseHas('pinpoint_queries', ['request_id' => $new]);
    $this->assertDatabaseMissing('pinpoint_lazy_loads', ['request_id' => $old]);
});

test('prune removes child rows of pruned requests via cascade', function () {
    $old = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.legacy', 'method' => 'GET', 'path' => 'api/legacy',
        'duration_ms' => 100, 'query_count' => 1, 'query_time_ms' => 10,
        'has_n_plus_one' => false, 'created_at' => now()->subDays(60),
    ]);

    DB::table('pinpoint_lazy_loads')->insert([
        ['request_id' => $old, 'model' => 'App\Models\User', 'relation' => 'posts', 'caller_file' => null, 'caller_line' => null, 'created_at' => now()->subDays(60)],
    ]);

    $this->artisan('pinpoint:prune')->assertSuccessful();

    $this->assertDatabaseCount('pinpoint_lazy_loads', 0);
});

test('prune rejects invalid retention windows', function () {
    DB::table('pinpoint_requests')->insert([
        ['route_name' => 'api.mid', 'method' => 'GET', 'path' => 'api/mid', 'duration_ms' => 100, 'query_count' => 1, 'query_time_ms' => 10, 'has_n_plus_one' => false, 'created_at' => now()],
    ]);

    $this->artisan('pinpoint:prune --days=0')->assertFailed();
    $this->artisan('pinpoint:prune --days=abc')->assertFailed();

    $this->assertDatabaseCount('pinpoint_requests', 1);
});
