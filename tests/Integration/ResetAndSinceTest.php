<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::table('pinpoint_requests')->truncate();
    DB::table('pinpoint_queries')->truncate();
    DB::table('pinpoint_lazy_loads')->truncate();
    DB::table('pinpoint_summaries')->truncate();
});

test('report since filter excludes stale samples from tiers', function () {
    // Old pre-fix sample: slow + N+1. Must be invisible with --since.
    $old = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders',
        'duration_ms' => 9000, 'query_count' => 72, 'query_time_ms' => 5000,
        'has_n_plus_one' => true, 'created_at' => now()->subHours(2),
    ]);
    for ($i = 0; $i < 4; $i++) {
        DB::table('pinpoint_queries')->insert([
            'request_id' => $old, 'sql_fingerprint' => 'abc', 'sql' => 'select * from orders where user_id = ?',
            'bindings_hash' => 'hash-'.$i,
            'time_ms' => 10, 'caller_file' => null, 'caller_line' => null, 'created_at' => now()->subHours(2),
        ]);
    }

    // Fresh post-fix samples.
    DB::table('pinpoint_requests')->insert([
        ['route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders', 'duration_ms' => 120, 'query_count' => 5, 'query_time_ms' => 20, 'has_n_plus_one' => false, 'created_at' => now()],
        ['route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders', 'duration_ms' => 136, 'query_count' => 5, 'query_time_ms' => 20, 'has_n_plus_one' => false, 'created_at' => now()],
    ]);

    // Without --since: stale sample skews it.
    $full = runArtisanCaptured('pinpoint:report');
    expect($full)->toContain('CRITICAL')->toContain('Yes (x4)');

    // With --since=60: only fresh samples (p95 136ms -> GOOD, no N+1).
    $fresh = runArtisanCaptured('pinpoint:report', ['--since' => 60]);
    expect($fresh)
        ->toContain('GOOD')
        ->toContain('136')
        ->not->toContain('CRITICAL')
        ->not->toContain('Yes');
});

test('report since accepts flexible durations like 5m and 1h', function () {
    DB::table('pinpoint_requests')->insert([
        ['route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders', 'duration_ms' => 9000, 'query_count' => 1, 'query_time_ms' => 100, 'has_n_plus_one' => false, 'created_at' => now()->subHours(2)],
        ['route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders', 'duration_ms' => 120, 'query_count' => 1, 'query_time_ms' => 10, 'has_n_plus_one' => false, 'created_at' => now()],
    ]);

    // 1h excludes the 2-hour-old sample.
    $fresh = runArtisanCaptured('pinpoint:report', ['--since' => '1h']);

    expect($fresh)
        ->toContain('GOOD')
        ->not->toContain('CRITICAL');
});

test('report rejects invalid since values', function () {
    $output = runArtisanCaptured('pinpoint:report', ['--since' => 'abc']);

    expect($output)->toContain('Invalid duration');
});

test('reset deletes all recorded data with force', function () {
    DB::table('pinpoint_requests')->insert([
        ['route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders', 'duration_ms' => 100, 'query_count' => 1, 'query_time_ms' => 10, 'has_n_plus_one' => false, 'created_at' => now()],
    ]);
    DB::table('pinpoint_summaries')->insert([
        ['route_name' => 'api.orders', 'p50_ms' => 100, 'p95_ms' => 100, 'p99_ms' => 100, 'avg_ms' => 100, 'sample_count' => 1, 'tier' => 'good', 'last_computed_at' => now()],
    ]);

    $this->artisan('pinpoint:reset --force')->assertSuccessful();

    expect(DB::table('pinpoint_requests')->count())->toBe(0)
        ->and(DB::table('pinpoint_queries')->count())->toBe(0)
        ->and(DB::table('pinpoint_lazy_loads')->count())->toBe(0)
        ->and(DB::table('pinpoint_summaries')->count())->toBe(0);
});

test('reset aborts without confirmation', function () {
    $this->artisan('pinpoint:reset')
        ->expectsQuestion('This deletes ALL recorded Pinpoint data. Continue?', false)
        ->assertSuccessful();
});

test('drill-down respects the since window', function () {
    // Old request with a lazy-load N+1 (outside the window).
    $old = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders',
        'duration_ms' => 500, 'query_count' => 10, 'query_time_ms' => 100,
        'has_n_plus_one' => true, 'created_at' => now()->subHours(2),
    ]);
    DB::table('pinpoint_queries')->insert([
        ['request_id' => $old, 'sql_fingerprint' => md5('select * from orders where user_id = ?'), 'sql' => 'select * from orders where user_id = ?', 'bindings_hash' => 'hash-1', 'time_ms' => 10, 'caller_file' => null, 'caller_line' => null, 'created_at' => now()->subHours(2)],
        ['request_id' => $old, 'sql_fingerprint' => md5('select * from orders where user_id = ?'), 'sql' => 'select * from orders where user_id = ?', 'bindings_hash' => 'hash-2', 'time_ms' => 10, 'caller_file' => null, 'caller_line' => null, 'created_at' => now()->subHours(2)],
        ['request_id' => $old, 'sql_fingerprint' => md5('select * from orders where user_id = ?'), 'sql' => 'select * from orders where user_id = ?', 'bindings_hash' => 'hash-3', 'time_ms' => 10, 'caller_file' => null, 'caller_line' => null, 'created_at' => now()->subHours(2)],
    ]);

    // Fresh request WITHOUT the N+1 (inside the window).
    $fresh = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders',
        'duration_ms' => 50, 'query_count' => 2, 'query_time_ms' => 5,
        'has_n_plus_one' => false, 'created_at' => now(),
    ]);
    DB::table('pinpoint_queries')->insert([
        ['request_id' => $fresh, 'sql_fingerprint' => md5('select * from orders where id in (1, 2)'), 'sql' => 'select * from orders where id in (1, 2)', 'time_ms' => 5, 'caller_file' => null, 'caller_line' => null, 'created_at' => now()],
    ]);

    // With --since: only the fresh request — no N+1 badge.
    $freshOutput = runArtisanCaptured('pinpoint:report', ['--route' => 'api.orders', '--since' => '5m']);
    expect($freshOutput)->not->toContain('N+1 x3');

    // Without --since: the old N+1 shows.
    $allOutput = runArtisanCaptured('pinpoint:report', ['--route' => 'api.orders']);
    expect($allOutput)->toContain('x3');
});
