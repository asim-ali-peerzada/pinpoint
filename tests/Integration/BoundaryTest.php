<?php

use Illuminate\Support\Facades\DB;

/**
 * Query repeat-count boundaries:
 * Evaluates the default threshold (3): x1 and x2 must not trigger an N+1 anomaly,
 * while x3 and above must be flagged.
 */
function seedRepeatRequest(string $route, string $fingerprint, int $repeats, array $bindingHashes): void
{
    $id = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => $route, 'method' => 'GET', 'path' => $route,
        'duration_ms' => 50, 'query_count' => $repeats, 'query_time_ms' => 1,
        'has_n_plus_one' => false, 'peak_memory_kb' => 2048, 'created_at' => now(),
    ]);

    $rows = [];

    for ($i = 0; $i < $repeats; $i++) {
        $rows[] = [
            'request_id' => $id, 'sql_fingerprint' => $fingerprint,
            'sql' => 'select * from users where id = ?',
            'bindings_hash' => $bindingHashes[$i] ?? 'hash-'.$i,
            'time_ms' => 1, 'caller_file' => 'app/Models/User.php', 'caller_line' => 10,
            'created_at' => now(),
        ];
    }

    DB::table('pinpoint_queries')->insert($rows);
}

beforeEach(function () {
    DB::table('pinpoint_requests')->truncate();
    DB::table('pinpoint_queries')->truncate();
    DB::table('pinpoint_lazy_loads')->truncate();
    DB::table('pinpoint_summaries')->truncate();
});

test('single occurrence is never an N+1 anomaly', function () {
    seedRepeatRequest('api.orders', 'fp1', 1, ['a']);

    $output = runArtisanCaptured('pinpoint:report');

    expect($output)->toContain('api.orders')
        ->not->toContain('Yes (x1)');
});

test('two occurrences are never an N+1 anomaly (below threshold)', function () {
    seedRepeatRequest('api.orders', 'fp1', 2, ['a', 'b']);

    $output = runArtisanCaptured('pinpoint:report');

    expect($output)->toContain('api.orders')
        ->not->toContain('Yes (x2)');
});

test('exactly three occurrences are an N+1 anomaly (at threshold)', function () {
    seedRepeatRequest('api.orders', 'fp1', 3, ['a', 'b', 'c']);

    $output = runArtisanCaptured('pinpoint:report');

    expect($output)->toContain('Yes (x3)');
});

test('four and five occurrences scale the repeat count', function () {
    seedRepeatRequest('api.orders', 'fp1', 4, ['a', 'b', 'c', 'd']);
    seedRepeatRequest('api.users', 'fp2', 5, ['a', 'b', 'c', 'd', 'e']);

    $output = runArtisanCaptured('pinpoint:report');

    expect($output)
        ->toContain('Yes (x4)')
        ->toContain('Yes (x5)');
});

test('the N+1 repeat threshold is configurable at the boundary', function () {
    config()->set('pinpoint.n_plus_one_repeat_threshold', 5);
    seedRepeatRequest('api.orders', 'fp1', 4, ['a', 'b', 'c', 'd']);

    $output = runArtisanCaptured('pinpoint:report');

    expect($output)->not->toContain('Yes (x4)');

    seedRepeatRequest('api.users', 'fp2', 5, ['a', 'b', 'c', 'd', 'e']);

    $output = runArtisanCaptured('pinpoint:report');

    expect($output)->toContain('Yes (x5)');
});

test('different fingerprints never merge into one repeat count', function () {
    // 2x fp1 + 2x fp2 — no fingerprint reaches the threshold, no anomaly.
    seedRepeatRequest('api.orders', 'fp1', 2, ['a', 'b']);
    seedRepeatRequest('api.orders', 'fp2', 2, ['a', 'b']);

    $output = runArtisanCaptured('pinpoint:report');

    expect($output)->not->toContain('Yes (x4)');
});
