<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verifies CI exit code contracts for automated regression gates.
 * Ensures each flag combination returns deterministic status codes,
 * and internal errors fail safe with exit code 1 to protect CI pipelines.
 */
function seedCheckRequest(string $route, int $durationMs, int $queryCount, bool $withN1 = false): void
{
    $id = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => $route, 'method' => 'GET', 'path' => $route,
        'duration_ms' => $durationMs, 'query_count' => $queryCount, 'query_time_ms' => 1,
        'has_n_plus_one' => $withN1, 'peak_memory_kb' => 2048, 'created_at' => now(),
    ]);

    if ($withN1) {
        $rows = [];

        for ($i = 0; $i < 3; $i++) {
            $rows[] = [
                'request_id' => $id, 'sql_fingerprint' => 'fp-n1', 'sql' => 'select * from users where id = ?',
                'bindings_hash' => 'hash-'.$i, 'time_ms' => 1, 'caller_file' => 'app/Models/User.php',
                'caller_line' => 10, 'created_at' => now(),
            ];
        }

        DB::table('pinpoint_queries')->insert($rows);
    }
}

beforeEach(function () {
    DB::table('pinpoint_requests')->truncate();
    DB::table('pinpoint_queries')->truncate();
    DB::table('pinpoint_lazy_loads')->truncate();
});

test('clean data passes every gate', function () {
    seedCheckRequest('api.orders', 100, 5);

    $this->artisan('pinpoint:check --fail-on-n1 --max-queries=10 --max-duration-ms=1000 --since=30')
        ->assertExitCode(0);
});

test('N+1 violation fails the N+1 gate', function () {
    seedCheckRequest('api.orders', 100, 5, withN1: true);

    $this->artisan('pinpoint:check --fail-on-n1 --since=30')->assertExitCode(1);
});

test('query budget violation fails the query gate', function () {
    seedCheckRequest('api.orders', 100, 50);

    $this->artisan('pinpoint:check --max-queries=10 --since=30')->assertExitCode(1);
});

test('duration budget violation fails the duration gate', function () {
    seedCheckRequest('api.orders', 5000, 5);

    $this->artisan('pinpoint:check --max-duration-ms=1000 --since=30')->assertExitCode(1);
});

test('multiple violations still fail when only one gate is enabled', function () {
    seedCheckRequest('api.orders', 5000, 50, withN1: true);

    // Each individual flag sees its own violation and fails.
    $this->artisan('pinpoint:check --fail-on-n1 --since=30')->assertExitCode(1);
    $this->artisan('pinpoint:check --max-queries=10 --since=30')->assertExitCode(1);
    $this->artisan('pinpoint:check --max-duration-ms=1000 --since=30')->assertExitCode(1);

    // And all flags together still fail.
    $this->artisan('pinpoint:check --fail-on-n1 --max-queries=10 --max-duration-ms=1000 --since=30')
        ->assertExitCode(1);
});

test('an internal Pinpoint error never reports success', function () {
    Schema::drop('pinpoint_requests');

    $this->artisan('pinpoint:check --fail-on-n1 --since=30')->assertExitCode(1);
});
