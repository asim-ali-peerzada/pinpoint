<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Malformed and corrupt data resilience: verifies that Pinpoint handles corrupt rows safely
 * and that database schema constraints (foreign keys, NOT NULL columns) prevent
 * invalid states by design.
 */
beforeEach(function () {
    DB::table('pinpoint_requests')->truncate();
    DB::table('pinpoint_queries')->truncate();
    DB::table('pinpoint_lazy_loads')->truncate();
    DB::table('pinpoint_baselines')->truncate();
});

test('negative and zero durations do not break the report', function () {
    DB::table('pinpoint_requests')->insert([
        ['route_name' => 'api.weird', 'method' => 'GET', 'path' => 'api/weird', 'duration_ms' => -5, 'query_count' => 0, 'query_time_ms' => 0, 'has_n_plus_one' => false, 'created_at' => now()],
        ['route_name' => 'api.zero', 'method' => 'GET', 'path' => 'api/zero', 'duration_ms' => 0, 'query_count' => 0, 'query_time_ms' => 0, 'has_n_plus_one' => false, 'created_at' => now()],
    ]);

    $output = runArtisanCaptured('pinpoint:report');

    expect($output)
        ->toContain('api.weird')
        ->toContain('api.zero');
});

test('rows with null route identity fall back to the method path label', function () {
    DB::table('pinpoint_requests')->insert([
        ['route_name' => null, 'method' => 'GET', 'path' => 'api/orphan', 'duration_ms' => 100, 'query_count' => 1, 'query_time_ms' => 1, 'has_n_plus_one' => false, 'created_at' => now()],
    ]);

    $output = runArtisanCaptured('pinpoint:report');

    expect($output)->toContain('GET api/orphan');
});

test('null bindings_hash rows (no-binding queries) do not break counting', function () {
    $id = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.nullish', 'method' => 'GET', 'path' => 'api/nullish', 'duration_ms' => 100,
        'query_count' => 2, 'query_time_ms' => 1, 'has_n_plus_one' => false, 'created_at' => now(),
    ]);

    DB::table('pinpoint_queries')->insert([
        ['request_id' => $id, 'sql_fingerprint' => 'fp1', 'sql' => 'select 1', 'bindings_hash' => null, 'time_ms' => 1, 'caller_file' => null, 'caller_line' => null, 'created_at' => now()],
        ['request_id' => $id, 'sql_fingerprint' => 'fp2', 'sql' => 'select 2', 'bindings_hash' => null, 'time_ms' => 1, 'caller_file' => null, 'caller_line' => null, 'created_at' => now()],
    ]);

    $output = runArtisanCaptured('pinpoint:report');

    expect($output)->toContain('api.nullish');
});

test('orphaned query rows are impossible: the foreign key rejects them', function () {
    $id = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.kept', 'method' => 'GET', 'path' => 'api/kept', 'duration_ms' => 100,
        'query_count' => 1, 'query_time_ms' => 1, 'has_n_plus_one' => false, 'created_at' => now(),
    ]);

    DB::table('pinpoint_requests')->where('id', $id)->delete();

    expect(fn () => DB::table('pinpoint_queries')->insert([
        'request_id' => $id, 'sql_fingerprint' => 'fp', 'sql' => 'select * from orphans',
        'time_ms' => 1, 'caller_file' => null, 'caller_line' => null, 'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('null fingerprints are impossible: the column is NOT NULL', function () {
    $id = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.x', 'method' => 'GET', 'path' => 'api/x', 'duration_ms' => 100,
        'query_count' => 1, 'query_time_ms' => 1, 'has_n_plus_one' => false, 'created_at' => now(),
    ]);

    expect(fn () => DB::table('pinpoint_queries')->insert([
        'request_id' => $id, 'sql_fingerprint' => null, 'sql' => 'select 1',
        'time_ms' => 1, 'caller_file' => null, 'caller_line' => null, 'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('deleting a request cascades to its queries (no orphans after prune)', function () {
    $id = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.x', 'method' => 'GET', 'path' => 'api/x', 'duration_ms' => 100,
        'query_count' => 1, 'query_time_ms' => 1, 'has_n_plus_one' => false, 'created_at' => now(),
    ]);

    DB::table('pinpoint_queries')->insert([
        'request_id' => $id, 'sql_fingerprint' => 'fp', 'sql' => 'select 1',
        'time_ms' => 1, 'caller_file' => null, 'caller_line' => null, 'created_at' => now(),
    ]);

    DB::table('pinpoint_requests')->where('id', $id)->delete();

    expect(DB::table('pinpoint_queries')->count())->toBe(0);
});

test('json output stays valid JSON with unusual rows present', function () {
    DB::table('pinpoint_requests')->insert([
        ['route_name' => null, 'method' => 'GET', 'path' => 'api/weird', 'duration_ms' => -1, 'query_count' => 0, 'query_time_ms' => 0, 'has_n_plus_one' => false, 'created_at' => now()],
    ]);

    $payload = json_decode(runArtisanCaptured('pinpoint:report', ['--json' => true]), true);

    expect(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($payload)->toBeArray();
});

test('reset and prune are safe on unusual rows', function () {
    DB::table('pinpoint_requests')->insert([
        ['route_name' => null, 'method' => 'GET', 'path' => 'api/weird', 'duration_ms' => 500, 'query_count' => 0, 'query_time_ms' => 0, 'has_n_plus_one' => false, 'created_at' => now()],
    ]);

    $this->artisan('pinpoint:prune')->assertExitCode(0);
    $this->artisan('pinpoint:reset --force')->assertExitCode(0);

    expect(DB::table('pinpoint_requests')->count())->toBe(0);
});
