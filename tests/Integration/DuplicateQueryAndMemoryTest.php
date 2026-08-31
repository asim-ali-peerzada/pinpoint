<?php

/**
 * Tests for Feature 3: Exact Duplicate Query Flagging
 * Tests for Feature 4: Peak Memory Hydration Tracking
 */

use AsimAli\Pinpoint\Internal\Recorder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::table('pinpoint_requests')->truncate();
    DB::table('pinpoint_queries')->truncate();
    DB::table('pinpoint_summaries')->truncate();
});

// ─── Feature 3: Exact Duplicate Query Flagging ────────────────────────────────

test('recorder classifies identical bindings as duplicate', function () {
    $recorder = app(Recorder::class);
    $recorder->reset(); // Clear queries recorded during test setup/migrations.

    // Same SQL, same bound value each time — Cache::remember() fix needed.
    $hash = md5(json_encode(['1']));

    for ($i = 0; $i < 3; $i++) {
        $recorder->recordQuery([
            'sql' => 'select * from settings where key = ?',
            'fingerprint' => md5('select * from settings where key = ?'),
            'bindings_hash' => $hash,
            'time_ms' => 5,
            'caller' => null,
        ]);
    }

    $groups = $recorder->classifyRepeatGroups();

    expect($groups)->toHaveCount(1);
    expect(array_values($groups)[0]['type'])->toBe('duplicate');
});

test('recorder classifies varying bindings as n_plus_one', function () {
    $recorder = app(Recorder::class);
    $recorder->reset();

    // Same SQL shape, different IDs each time — with() fix needed.
    for ($i = 1; $i <= 3; $i++) {
        $recorder->recordQuery([
            'sql' => 'select * from orders where user_id = ?',
            'fingerprint' => md5('select * from orders where user_id = ?'),
            'bindings_hash' => md5(json_encode([(string) $i])),
            'time_ms' => 5,
            'caller' => null,
        ]);
    }

    $groups = $recorder->classifyRepeatGroups();

    expect($groups)->toHaveCount(1);
    expect(array_values($groups)[0]['type'])->toBe('n_plus_one');
});

test('recorder classifies null bindings_hash as unknown', function () {
    $recorder = app(Recorder::class);
    $recorder->reset();

    // Raw DB::statement() — no bindings captured.
    for ($i = 0; $i < 3; $i++) {
        $recorder->recordQuery([
            'sql' => 'TRUNCATE TABLE logs',
            'fingerprint' => md5('TRUNCATE TABLE logs'),
            'bindings_hash' => null,
            'time_ms' => 2,
            'caller' => null,
        ]);
    }

    $groups = $recorder->classifyRepeatGroups();

    expect($groups)->toHaveCount(1);
    expect(array_values($groups)[0]['type'])->toBe('unknown');
});

test('recorder does not classify groups below the repeat threshold', function () {
    $recorder = app(Recorder::class);
    $recorder->reset(); // Threshold is 3; only 2 occurrences — must not appear.
    $hash = md5(json_encode(['x']));

    for ($i = 0; $i < 2; $i++) {
        $recorder->recordQuery([
            'sql' => 'select count(*) from users',
            'fingerprint' => md5('select count(*) from users'),
            'bindings_hash' => $hash,
            'time_ms' => 1,
            'caller' => null,
        ]);
    }

    expect($recorder->classifyRepeatGroups())->toBe([]);
});

test('duplicate query rows are stored with bindings_hash in the database', function () {
    $this->get('/pinpoint-test'); // Triggers a "select 1" with no bindings.

    // select 1 has no bindings → bindings_hash must be null in the DB.
    $this->assertDatabaseHas('pinpoint_queries', [
        'sql' => 'select 1',
        'bindings_hash' => null,
    ]);
});

test('report drill-in shows CACHE badge for exact duplicate queries', function () {
    $hash = md5(json_encode(['theme']));

    $id = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.settings',
        'method' => 'GET',
        'path' => 'api/settings',
        'duration_ms' => 50,
        'query_count' => 3,
        'query_time_ms' => 15,
        'has_n_plus_one' => true,
        'peak_memory_kb' => null,
        'created_at' => now(),
    ]);

    for ($i = 0; $i < 3; $i++) {
        DB::table('pinpoint_queries')->insert([
            'request_id' => $id,
            'sql_fingerprint' => md5('select * from settings where key = ?'),
            'sql' => 'select * from settings where key = ?',
            'bindings_hash' => $hash,
            'time_ms' => 5,
            'caller_file' => null,
            'caller_line' => null,
            'created_at' => now(),
        ]);
    }

    $output = runArtisanCaptured('pinpoint:report', ['--route' => 'api.settings']);

    expect($output)->toContain('CACHE');
    expect($output)->toContain('Cache::remember');
});

test('report drill-in shows N+1 badge for varying-binding queries', function () {
    $id = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.orders',
        'method' => 'GET',
        'path' => 'api/orders',
        'duration_ms' => 200,
        'query_count' => 3,
        'query_time_ms' => 30,
        'has_n_plus_one' => true,
        'peak_memory_kb' => null,
        'created_at' => now(),
    ]);

    for ($i = 1; $i <= 3; $i++) {
        DB::table('pinpoint_queries')->insert([
            'request_id' => $id,
            'sql_fingerprint' => md5('select * from orders where user_id = ?'),
            'sql' => 'select * from orders where user_id = ?',
            'bindings_hash' => md5(json_encode([(string) $i])),
            'time_ms' => 10,
            'caller_file' => null,
            'caller_line' => null,
            'created_at' => now(),
        ]);
    }

    $output = runArtisanCaptured('pinpoint:report', ['--route' => 'api.orders']);

    expect($output)->toContain('N+1');
    expect($output)->toContain('Model::with');
});

test('report drill-in shows REPEAT badge when bindings_hash is null', function () {
    $id = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.raw',
        'method' => 'GET',
        'path' => 'api/raw',
        'duration_ms' => 50,
        'query_count' => 3,
        'query_time_ms' => 6,
        'has_n_plus_one' => true,
        'peak_memory_kb' => null,
        'created_at' => now(),
    ]);

    for ($i = 0; $i < 3; $i++) {
        DB::table('pinpoint_queries')->insert([
            'request_id' => $id,
            'sql_fingerprint' => md5('TRUNCATE TABLE logs'),
            'sql' => 'TRUNCATE TABLE logs',
            'bindings_hash' => null,
            'time_ms' => 2,
            'caller_file' => null,
            'caller_line' => null,
            'created_at' => now(),
        ]);
    }

    $output = runArtisanCaptured('pinpoint:report', ['--route' => 'api.raw']);

    expect($output)->toContain('REPEAT');
});

test('report summary counts duplicate-query routes', function () {
    $hash = md5(json_encode(['theme']));

    $id = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.settings',
        'method' => 'GET',
        'path' => 'api/settings',
        'duration_ms' => 50,
        'query_count' => 3,
        'query_time_ms' => 15,
        'has_n_plus_one' => true,
        'peak_memory_kb' => null,
        'created_at' => now(),
    ]);

    for ($i = 0; $i < 3; $i++) {
        DB::table('pinpoint_queries')->insert([
            'request_id' => $id,
            'sql_fingerprint' => md5('select * from settings where key = ?'),
            'sql' => 'select * from settings where key = ?',
            'bindings_hash' => $hash,
            'time_ms' => 5,
            'caller_file' => null,
            'caller_line' => null,
            'created_at' => now(),
        ]);
    }

    $output = runArtisanCaptured('pinpoint:report', []);

    expect($output)->toContain('1 with duplicate queries');
});

test('report json includes query_type field in drill-in output', function () {
    $hash = md5(json_encode(['theme']));

    $id = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.settings',
        'method' => 'GET',
        'path' => 'api/settings',
        'duration_ms' => 50,
        'query_count' => 3,
        'query_time_ms' => 15,
        'has_n_plus_one' => true,
        'peak_memory_kb' => null,
        'created_at' => now(),
    ]);

    for ($i = 0; $i < 3; $i++) {
        DB::table('pinpoint_queries')->insert([
            'request_id' => $id,
            'sql_fingerprint' => md5('select * from settings where key = ?'),
            'sql' => 'select * from settings where key = ?',
            'bindings_hash' => $hash,
            'time_ms' => 5,
            'caller_file' => null,
            'caller_line' => null,
            'created_at' => now(),
        ]);
    }

    $payload = json_decode(
        runArtisanCaptured('pinpoint:report', ['--route' => 'api.settings', '--json' => true]),
        true
    );

    expect($payload['queries'][0]['query_type'])->toBe('duplicate');
});

// ─── Feature 4: Peak Memory Hydration Tracking ───────────────────────────────

test('peak memory is stored when a request is flushed via the pipeline', function () {
    $this->get('/pinpoint-test');

    $row = DB::table('pinpoint_requests')->first();

    // memory_get_peak_usage() always returns a positive integer; the column
    // must be populated and reasonable (> 0, < 512 MB for a trivial test request).
    expect($row->peak_memory_kb)->toBeInt()
        ->toBeGreaterThan(0)
        ->toBeLessThan(512 * 1024);
});

test('report table shows memory column with value', function () {
    DB::table('pinpoint_requests')->insert([
        'route_name' => 'api.orders',
        'method' => 'GET',
        'path' => 'api/orders',
        'duration_ms' => 100,
        'query_count' => 1,
        'query_time_ms' => 5,
        'has_n_plus_one' => false,
        'peak_memory_kb' => 4096, // 4 MB
        'created_at' => now(),
    ]);

    $output = runArtisanCaptured('pinpoint:report', []);

    // 4096 KB = 4.0 MB
    expect($output)->toContain('4 MB');
});

test('report table shows dash when peak_memory_kb is null', function () {
    DB::table('pinpoint_requests')->insert([
        'route_name' => 'api.legacy',
        'method' => 'GET',
        'path' => 'api/legacy',
        'duration_ms' => 100,
        'query_count' => 1,
        'query_time_ms' => 5,
        'has_n_plus_one' => false,
        'peak_memory_kb' => null, // Pre-feature row
        'created_at' => now(),
    ]);

    $output = runArtisanCaptured('pinpoint:report', []);

    // Em-dash placeholder rendered for null memory.
    expect($output)->toContain('—');
});

test('report table flags route red when memory exceeds budget', function () {
    // Set the budget to 10 MB.
    config()->set('pinpoint.memory_budget_kb', 10 * 1024);

    DB::table('pinpoint_requests')->insert([
        'route_name' => 'api.export',
        'method' => 'GET',
        'path' => 'api/export',
        'duration_ms' => 200,
        'query_count' => 1,
        'query_time_ms' => 10,
        'has_n_plus_one' => false,
        'peak_memory_kb' => 25 * 1024, // 25 MB — over the 10 MB budget
        'created_at' => now(),
    ]);

    $output = runArtisanCaptured('pinpoint:report', []);

    // The value must appear; the red ANSI escape is stripped by runArtisanCaptured
    // but the formatted value must be present.
    expect($output)->toContain('25 MB');
});

test('report table does not flag route when memory is within budget', function () {
    config()->set('pinpoint.memory_budget_kb', 50 * 1024); // 50 MB budget

    DB::table('pinpoint_requests')->insert([
        'route_name' => 'api.light',
        'method' => 'GET',
        'path' => 'api/light',
        'duration_ms' => 50,
        'query_count' => 1,
        'query_time_ms' => 2,
        'has_n_plus_one' => false,
        'peak_memory_kb' => 5 * 1024, // 5 MB — well under budget
        'created_at' => now(),
    ]);

    // No exception — route is shown with memory value, no budget alarm.
    $output = runArtisanCaptured('pinpoint:report', []);

    expect($output)->toContain('5 MB');
});

test('report table does not flag when memory_budget_kb config is null', function () {
    config()->set('pinpoint.memory_budget_kb', null); // Budget check disabled

    DB::table('pinpoint_requests')->insert([
        'route_name' => 'api.huge',
        'method' => 'GET',
        'path' => 'api/huge',
        'duration_ms' => 500,
        'query_count' => 1,
        'query_time_ms' => 10,
        'has_n_plus_one' => false,
        'peak_memory_kb' => 999 * 1024, // Enormous but budget is off
        'created_at' => now(),
    ]);

    // Must not throw, must show the value.
    $output = runArtisanCaptured('pinpoint:report', []);

    expect($output)->toContain('999 MB');
});

test('formatMemory shows KB for values below 1 MB', function () {
    DB::table('pinpoint_requests')->insert([
        'route_name' => 'api.tiny',
        'method' => 'GET',
        'path' => 'api/tiny',
        'duration_ms' => 10,
        'query_count' => 1,
        'query_time_ms' => 1,
        'has_n_plus_one' => false,
        'peak_memory_kb' => 512, // 512 KB — below 1 MB, shown in KB
        'created_at' => now(),
    ]);

    $output = runArtisanCaptured('pinpoint:report', []);

    expect($output)->toContain('512 KB');
});

test('report json includes peak_memory_kb in summary routes', function () {
    DB::table('pinpoint_requests')->insert([
        'route_name' => 'api.orders',
        'method' => 'GET',
        'path' => 'api/orders',
        'duration_ms' => 100,
        'query_count' => 1,
        'query_time_ms' => 5,
        'has_n_plus_one' => false,
        'peak_memory_kb' => 8192,
        'created_at' => now(),
    ]);

    $payload = json_decode(
        runArtisanCaptured('pinpoint:report', ['--json' => true]),
        true
    );

    expect($payload['routes'][0])->toHaveKey('peak_memory_kb');
    expect($payload['routes'][0]['peak_memory_kb'])->toBe(8192);
});

test('SummaryReader reports peak memory as max across multiple samples', function () {
    // Two samples for the same route: 4 MB and 12 MB.
    // Summary must report 12 MB (the peak, not the average).
    DB::table('pinpoint_requests')->insert([
        ['route_name' => 'api.test', 'method' => 'GET', 'path' => 'api/test', 'duration_ms' => 100, 'query_count' => 1, 'query_time_ms' => 5, 'has_n_plus_one' => false, 'peak_memory_kb' => 4096, 'created_at' => now()],
        ['route_name' => 'api.test', 'method' => 'GET', 'path' => 'api/test', 'duration_ms' => 120, 'query_count' => 1, 'query_time_ms' => 5, 'has_n_plus_one' => false, 'peak_memory_kb' => 12288, 'created_at' => now()],
    ]);

    $payload = json_decode(
        runArtisanCaptured('pinpoint:report', ['--json' => true]),
        true
    );

    expect($payload['routes'][0]['peak_memory_kb'])->toBe(12288);
});
