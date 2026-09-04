<?php

use AsimAli\Pinpoint\Exceptions\BaselineException;
use AsimAli\Pinpoint\Internal\BaselineReader;
use AsimAli\Pinpoint\Internal\BaselineWriter;
use AsimAli\Pinpoint\Internal\SummaryReader;
use Illuminate\Support\Facades\DB;

function baselineWriter(): BaselineWriter
{
    return new BaselineWriter(app(SummaryReader::class));
}

function baselineReader(): BaselineReader
{
    return new BaselineReader;
}

function seedBaselineRequest(string $route, int $durationMs, int $queryCount = 1): void
{
    DB::table('pinpoint_requests')->insert([
        'route_name' => $route,
        'method' => 'GET',
        'path' => $route,
        'duration_ms' => $durationMs,
        'query_count' => $queryCount,
        'query_time_ms' => 1,
        'has_n_plus_one' => false,
        'peak_memory_kb' => 4096,
        'created_at' => now(),
    ]);
}

beforeEach(function () {
    DB::table('pinpoint_requests')->truncate();
    DB::table('pinpoint_baselines')->truncate();
});

test('writer rejects an empty tag', function () {
    expect(fn () => baselineWriter()->write('  '))->toThrow(InvalidArgumentException::class, 'cannot be empty');
});

test('writer rejects a tag longer than 100 characters', function () {
    expect(fn () => baselineWriter()->write(str_repeat('a', 101)))->toThrow(InvalidArgumentException::class, 'exceeds 100');
});

test('writer rejects tags with CLI-hostile characters', function () {
    expect(fn () => baselineWriter()->write('bad<tag>'))->toThrow(InvalidArgumentException::class, 'may only contain');
});

test('writer refuses to snapshot an empty window', function () {
    expect(fn () => baselineWriter()->write('main'))->toThrow(BaselineException::class, 'No requests recorded');
});

test('writer persists a snapshot with the computed route count', function () {
    seedBaselineRequest('api.orders', 100);
    seedBaselineRequest('api.users', 200);

    expect(baselineWriter()->write('main'))->toBe(2);

    $row = DB::table('pinpoint_baselines')->where('tag', 'main')->first();

    expect($row)->not->toBeNull()
        ->and($row->route_count)->toBe(2)
        ->and(json_decode($row->snapshot, true))->toHaveCount(2);
});

test('writer overwrites a duplicate tag by default', function () {
    seedBaselineRequest('api.orders', 100);
    baselineWriter()->write('main');
    baselineWriter()->write('main');

    expect(DB::table('pinpoint_baselines')->where('tag', 'main')->count())->toBe(1);
});

test('writer refuses to overwrite with --no-overwrite semantics', function () {
    seedBaselineRequest('api.orders', 100);
    baselineWriter()->write('main');

    expect(fn () => baselineWriter()->write('main', overwrite: false))
        ->toThrow(BaselineException::class, 'already exists');
});

test('reader lists available tags when the requested tag is missing', function () {
    seedBaselineRequest('api.orders', 100);
    baselineWriter()->write('v2.0');

    expect(fn () => baselineReader()->load('nope'))
        ->toThrow(BaselineException::class, 'not found')
        ->and(fn () => baselineReader()->load('nope'))->toThrow(BaselineException::class, 'v2.0');
});

test('reader hydrates missing keys from old snapshots with safe defaults', function () {
    // An old baseline written before the query_count key existed.
    DB::table('pinpoint_baselines')->insert([
        'tag' => 'legacy',
        'snapshot' => json_encode([[
            'route' => 'api.orders',
            'p50' => 80,
            'p95' => 100,
            'p99' => 120,
            'avg' => 90,
            'samples' => 10,
            'tier' => 'good',
            'n1_repeat' => 0,
            'peak_memory_kb' => 4096,
            'has_duplicate_queries' => false,
        ]]),
        'route_count' => 1,
        'created_at' => now(),
    ]);

    $rows = baselineReader()->load('legacy');

    expect($rows[0]['query_count'])->toBe(0)
        ->and($rows[0]['p95'])->toBe(100);
});

test('reader reports a corrupt snapshot instead of crashing', function () {
    DB::table('pinpoint_baselines')->insert([
        'tag' => 'corrupt',
        'snapshot' => '{not json',
        'route_count' => 1,
        'created_at' => now(),
    ]);

    expect(fn () => baselineReader()->load('corrupt'))
        ->toThrow(BaselineException::class, 'corrupt');
});

test('reader round-trips a writer snapshot', function () {
    seedBaselineRequest('api.orders', 100, 4);

    baselineWriter()->write('main');

    $rows = baselineReader()->load('main');

    expect($rows[0]['route'])->toBe('api.orders')
        ->and($rows[0]['p95'])->toBe(100)
        ->and($rows[0]['query_count'])->toBe(4);
});
