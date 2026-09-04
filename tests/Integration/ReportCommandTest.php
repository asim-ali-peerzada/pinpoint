<?php

use AsimAli\Pinpoint\Internal\QueryReader;
use Illuminate\Support\Facades\DB;

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
        ['request_id' => $id, 'sql_fingerprint' => 'abc', 'sql' => 'select * from orders where user_id = ?', 'bindings_hash' => 'hash-1', 'time_ms' => 10, 'caller_file' => 'app/Models/Order.php', 'caller_line' => 12, 'created_at' => now()],
        ['request_id' => $id, 'sql_fingerprint' => 'abc', 'sql' => 'select * from orders where user_id = ?', 'bindings_hash' => 'hash-2', 'time_ms' => 10, 'caller_file' => 'app/Models/Order.php', 'caller_line' => 12, 'created_at' => now()],
        ['request_id' => $id, 'sql_fingerprint' => 'abc', 'sql' => 'select * from orders where user_id = ?', 'bindings_hash' => 'hash-3', 'time_ms' => 10, 'caller_file' => 'app/Models/Order.php', 'caller_line' => 12, 'created_at' => now()],
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

test('report prints a summary line with route, critical and N+1 counts', function () {
    $criticalId = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.slow', 'method' => 'GET', 'path' => 'api/slow',
        'duration_ms' => 9000, 'query_count' => 3, 'query_time_ms' => 100,
        'has_n_plus_one' => false, 'created_at' => now(),
    ]);
    DB::table('pinpoint_requests')->insert([
        ['route_name' => 'api.critical2', 'method' => 'GET', 'path' => 'api/critical2', 'duration_ms' => 9500, 'query_count' => 1, 'query_time_ms' => 10, 'has_n_plus_one' => false, 'created_at' => now()],
        ['route_name' => 'api.fast', 'method' => 'GET', 'path' => 'api/fast', 'duration_ms' => 10, 'query_count' => 1, 'query_time_ms' => 1, 'has_n_plus_one' => false, 'created_at' => now()],
    ]);

    for ($i = 0; $i < 3; $i++) {
        DB::table('pinpoint_queries')->insert([
            'request_id' => $criticalId, 'sql_fingerprint' => 'fp1', 'sql' => 'select * from orders',
            'bindings_hash' => 'hash-'.$i,
            'time_ms' => 5, 'caller_file' => null, 'caller_line' => null, 'created_at' => now(),
        ]);
    }

    $output = runReport();

    expect($output)
        ->toContain('3 route(s) · 2 critical · 1 with N+1')
        ->toContain('Performance Report');
});

test('report shows the since window in the title', function () {
    DB::table('pinpoint_requests')->insert([
        ['route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders', 'duration_ms' => 10, 'query_count' => 1, 'query_time_ms' => 1, 'has_n_plus_one' => false, 'created_at' => now()],
    ]);

    $output = runReport(['--since' => '30m']);

    expect($output)->toContain('Performance Report · last 30 min');
});

test('report truncates route labels beyond 40 chars', function () {
    $long = str_repeat('a', 35).'very-long-suffix';

    DB::table('pinpoint_requests')->insert([
        ['route_name' => $long, 'method' => 'GET', 'path' => 'api/long', 'duration_ms' => 10, 'query_count' => 1, 'query_time_ms' => 1, 'has_n_plus_one' => false, 'created_at' => now()],
    ]);

    $output = runReport();

    // 40-column cap incl. the ellipsis: 35 a's + 'very' + '…'.
    expect($output)
        ->toContain(str_repeat('a', 35).'very…')
        ->not->toContain($long)
        ->not->toContain('long-suffix');
});

test('report --json emits machine-readable summary', function () {
    DB::table('pinpoint_requests')->insert([
        ['route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders', 'duration_ms' => 9000, 'query_count' => 1, 'query_time_ms' => 1, 'has_n_plus_one' => false, 'created_at' => now()],
    ]);

    $payload = json_decode(runReport(['--json' => true]), true);

    expect($payload['meta']['window_minutes'])->toBeNull()
        ->and($payload['routes'])->toHaveCount(1)
        ->and($payload['routes'][0]['route'])->toBe('api.orders')
        ->and($payload['routes'][0]['tier'])->toBe('critical')
        ->and($payload['routes'][0]['n1_repeat'])->toBeInt();
});

test('report --json honors since and tier filters', function () {
    DB::table('pinpoint_requests')->insert([
        ['route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders', 'duration_ms' => 9000, 'query_count' => 1, 'query_time_ms' => 1, 'has_n_plus_one' => false, 'created_at' => now()],
        ['route_name' => 'api.fast', 'method' => 'GET', 'path' => 'api/fast', 'duration_ms' => 10, 'query_count' => 1, 'query_time_ms' => 1, 'has_n_plus_one' => false, 'created_at' => now()],
        ['route_name' => 'api.stale', 'method' => 'GET', 'path' => 'api/stale', 'duration_ms' => 9500, 'query_count' => 1, 'query_time_ms' => 1, 'has_n_plus_one' => false, 'created_at' => now()->subHours(2)],
    ]);

    $payload = json_decode(runReport(['--json' => true, '--since' => '30m', '--tier' => 'critical']), true);

    expect($payload['meta']['window_minutes'])->toBe(30)
        ->and($payload['routes'])->toHaveCount(1)
        ->and($payload['routes'][0]['route'])->toBe('api.orders');
});

test('report --json --route emits drill-in queries and suggestions', function () {
    $id = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders',
        'duration_ms' => 9000, 'query_count' => 3, 'query_time_ms' => 100,
        'has_n_plus_one' => false, 'created_at' => now(),
    ]);

    for ($i = 0; $i < 3; $i++) {
        DB::table('pinpoint_queries')->insert([
            'request_id' => $id, 'sql_fingerprint' => 'fp1', 'sql' => 'select * from orders',
            'time_ms' => 5, 'caller_file' => 'app/OrderController.php', 'caller_line' => 12, 'created_at' => now(),
        ]);
    }

    $payload = json_decode(runReport(['--json' => true, '--route' => 'api.orders']), true);

    expect($payload['route'])->toBe('api.orders')
        ->and($payload['queries'])->toHaveCount(1)
        ->and($payload['queries'][0]['repeat_count'])->toBe(3)
        ->and($payload['queries'][0]['caller_file'])->toBe('app/OrderController.php')
        ->and($payload['suggestions'])->toBe([]);
});

test('report rejects an invalid tier instead of showing an empty table', function () {
    DB::table('pinpoint_requests')->insert([
        ['route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders', 'duration_ms' => 10, 'query_count' => 1, 'query_time_ms' => 1, 'has_n_plus_one' => false, 'created_at' => now()],
    ]);

    $output = runReport(['--tier' => 'bogus']);

    expect($output)
        ->toContain('Invalid --tier value "bogus"')
        ->toContain('good, acceptable, needs_improvement, critical');
});

test('report --json-to writes the payload to a file and prints its location', function () {
    DB::table('pinpoint_requests')->insert([
        ['route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders', 'duration_ms' => 9000, 'query_count' => 1, 'query_time_ms' => 1, 'has_n_plus_one' => false, 'created_at' => now()],
    ]);

    $relative = 'storage/pinpoint-test/report.json';
    $absolute = base_path($relative);

    @unlink($absolute);

    $output = runReport(['--json-to' => $relative]);

    expect($output)->toContain('JSON written to '.$absolute);

    $payload = json_decode((string) file_get_contents($absolute), true);

    expect($payload['routes'])->toHaveCount(1)
        ->and($payload['routes'][0]['route'])->toBe('api.orders');

    @unlink($absolute);
});

test('report accepts tier values case-insensitively', function () {
    DB::table('pinpoint_requests')->insert([
        ['route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders', 'duration_ms' => 10, 'query_count' => 1, 'query_time_ms' => 1, 'has_n_plus_one' => false, 'created_at' => now()],
    ]);

    $output = runReport(['--tier' => 'GOOD']);

    expect($output)->toContain('api.orders');
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

test('route label scope matches named and fallback rows but not other methods', function () {
    $named = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'GET api/orders', 'method' => 'GET', 'path' => 'api/orders',
        'duration_ms' => 10, 'query_count' => 1, 'query_time_ms' => 1,
        'has_n_plus_one' => false, 'created_at' => now(),
    ]);
    $fallback = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => null, 'method' => 'GET', 'path' => 'api/orders',
        'duration_ms' => 20, 'query_count' => 1, 'query_time_ms' => 1,
        'has_n_plus_one' => false, 'created_at' => now(),
    ]);
    $otherMethod = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => null, 'method' => 'POST', 'path' => 'api/orders',
        'duration_ms' => 30, 'query_count' => 1, 'query_time_ms' => 1,
        'has_n_plus_one' => false, 'created_at' => now(),
    ]);

    $ids = QueryReader::scopeRouteLabel(
        DB::table('pinpoint_requests')->select('id'),
        'GET api/orders'
    )->pluck('id')->all();

    expect($ids)->toContain($named)->toContain($fallback)->not->toContain($otherMethod);
});

test('report handles missing tables without breaking', function () {
    Schema::drop('pinpoint_requests');
    Schema::drop('pinpoint_queries');

    $this->artisan('pinpoint:report')->assertFailed();
});

function runReport(array $parameters = []): string
{
    return runArtisanCaptured('pinpoint:report', $parameters);
}

test('report --json on an empty database emits a valid empty payload', function () {
    $payload = json_decode(runReport(['--json' => true]), true);

    expect(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($payload['meta']['empty'])->toBeTrue()
        ->and($payload['routes'])->toBe([]);
});
