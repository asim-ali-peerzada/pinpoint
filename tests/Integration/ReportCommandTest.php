<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function () {
    DB::table('pinpoint_requests')->truncate();
    DB::table('pinpoint_queries')->truncate();
    DB::table('pinpoint_summaries')->truncate();
});

test('report shows per-route summary with tiers and n plus one', function () {
    $id = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders',
        'duration_ms' => 5000, 'query_count' => 2, 'query_time_ms' => 100,
        'has_n_plus_one' => true, 'created_at' => now(),
    ]);

    DB::table('pinpoint_queries')->insert([
        ['request_id' => $id, 'sql_fingerprint' => 'abc', 'sql' => 'select * from orders where user_id = ?', 'time_ms' => 10, 'caller_file' => 'app/Models/Order.php', 'caller_line' => 12, 'created_at' => now()],
        ['request_id' => $id, 'sql_fingerprint' => 'abc', 'sql' => 'select * from orders where user_id = ?', 'time_ms' => 10, 'caller_file' => 'app/Models/Order.php', 'caller_line' => 12, 'created_at' => now()],
        ['request_id' => $id, 'sql_fingerprint' => 'abc', 'sql' => 'select * from orders where user_id = ?', 'time_ms' => 10, 'caller_file' => 'app/Models/Order.php', 'caller_line' => 12, 'created_at' => now()],
    ]);

    $output = runReport();

    expect($output)
        ->toContain('api.orders')
        ->toContain('CRITICAL')
        ->toContain('Yes (x3)');
});

test('report tier filter only shows matching routes', function () {
    DB::table('pinpoint_requests')->insert([
        ['route_name' => 'api.fast', 'method' => 'GET', 'path' => 'api/fast', 'duration_ms' => 10, 'query_count' => 1, 'query_time_ms' => 1, 'has_n_plus_one' => false, 'created_at' => now()],
        ['route_name' => 'api.slow', 'method' => 'GET', 'path' => 'api/slow', 'duration_ms' => 5000, 'query_count' => 1, 'query_time_ms' => 1, 'has_n_plus_one' => false, 'created_at' => now()],
    ]);

    $output = runReport(['--tier' => 'critical']);

    expect($output)
        ->toContain('api.slow')
        ->not->toContain('api.fast');
});

test('report drill into route shows offending queries with caller', function () {
    $id = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders',
        'duration_ms' => 5000, 'query_count' => 1, 'query_time_ms' => 100,
        'has_n_plus_one' => false, 'created_at' => now(),
    ]);

    DB::table('pinpoint_queries')->insert([
        ['request_id' => $id, 'sql_fingerprint' => 'abc', 'sql' => 'select * from orders where user_id = ?', 'time_ms' => 99, 'caller_file' => 'app/Models/Order.php', 'caller_line' => 12, 'created_at' => now()],
    ]);

    $output = runReport(['--route' => 'api.orders']);

    expect($output)
        ->toContain('select * from orders where user_id = ?')
        ->toContain('app/Models/Order.php:12');
});

test('report handles empty data', function () {
    $output = runReport();

    expect($output)->toContain('No requests recorded yet.');
});

test('report groups unlabeled routes by method and path', function () {
    DB::table('pinpoint_requests')->insert([
        ['route_name' => null, 'method' => 'GET', 'path' => 'api/fast', 'duration_ms' => 10, 'query_count' => 1, 'query_time_ms' => 1, 'has_n_plus_one' => false, 'created_at' => now()],
        ['route_name' => null, 'method' => 'GET', 'path' => 'api/slow', 'duration_ms' => 9000, 'query_count' => 1, 'query_time_ms' => 1, 'has_n_plus_one' => false, 'created_at' => now()],
    ]);

    $output = runReport();

    expect($output)
        ->toContain('GET api/fast')
        ->toContain('GET api/slow');
});

test('aggregate groups unlabeled routes by method and path', function () {
    DB::table('pinpoint_requests')->insert([
        ['route_name' => null, 'method' => 'GET', 'path' => 'api/fast', 'duration_ms' => 10, 'query_count' => 1, 'query_time_ms' => 1, 'has_n_plus_one' => false, 'created_at' => now()],
        ['route_name' => null, 'method' => 'POST', 'path' => 'api/fast', 'duration_ms' => 9000, 'query_count' => 1, 'query_time_ms' => 1, 'has_n_plus_one' => false, 'created_at' => now()],
    ]);

    $this->artisan('pinpoint:aggregate')->assertSuccessful();

    $this->assertDatabaseHas('pinpoint_summaries', ['route_name' => 'GET api/fast']);
    $this->assertDatabaseHas('pinpoint_summaries', ['route_name' => 'POST api/fast']);
});

test('report handles missing tables without breaking', function () {
    Schema::drop('pinpoint_requests');
    Schema::drop('pinpoint_queries');

    $this->artisan('pinpoint:report')->assertFailed();
});

function runReport(array $parameters = []): string
{
    $buffer = new BufferedOutput;

    Artisan::call('pinpoint:report', $parameters, $buffer);

    return $buffer->fetch();
}
