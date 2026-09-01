<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function () {
    DB::table('pinpoint_requests')->truncate();
    DB::table('pinpoint_queries')->truncate();
    DB::table('pinpoint_lazy_loads')->truncate();
    config()->set('pinpoint.composite_tier', false);
});

function seedRoute(string $name, int $durationMs, int $repeats, ?int $peakKb, int $budgetKb = 20480): void
{
    config()->set('pinpoint.memory_budget_kb', $budgetKb);

    $id = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => $name, 'method' => 'GET', 'path' => str_replace('.', '/', $name),
        'duration_ms' => $durationMs, 'query_count' => max(1, $repeats), 'query_time_ms' => 10,
        'has_n_plus_one' => $repeats >= 3, 'peak_memory_kb' => $peakKb, 'created_at' => now(),
    ]);

    for ($i = 0; $i < $repeats; $i++) {
        DB::table('pinpoint_queries')->insert([
            'request_id' => $id,
            'sql_fingerprint' => md5('select * from t where id = ?'),
            'sql' => 'select * from t where id = ?',
            'bindings_hash' => md5(json_encode([$i])),
            'time_ms' => 1, 'caller_file' => null, 'caller_line' => null, 'created_at' => now(),
        ]);
    }
}

test('composite off by default keeps p95-only tier header', function () {
    seedRoute('api.fast', 50, 0, 4096);

    $output = runArtisanCaptured('pinpoint:report');

    expect($output)->toContain('Tier (p95 only)')
        ->not->toContain('Health (');
});

test('composite on shows HEALTHY only when tier good, no N+1, memory within budget', function () {
    config()->set('pinpoint.composite_tier', true);

    seedRoute('api.clean', 50, 0, 4096);   // good tier, no N+1, 4MB < 20MB

    $output = runArtisanCaptured('pinpoint:report');

    expect($output)->toContain('Health (tier + N+1 + memory)')
        ->toContain('HEALTHY');
});

test('composite flags fast route with N+1 as NEEDS WORK (GOOD)', function () {
    config()->set('pinpoint.composite_tier', true);

    seedRoute('api.fast-but-n1', 50, 5, 4096);  // fast, but 5 varying repeats

    $output = runArtisanCaptured('pinpoint:report');

    expect($output)->toContain('NEEDS WORK')
        ->toContain('(GOOD)');
});

test('composite flags fast route over memory budget as NEEDS WORK', function () {
    config()->set('pinpoint.composite_tier', true);

    seedRoute('api.memory-hog', 50, 0, 30 * 1024); // fast, no N+1, 30MB > 20MB

    $output = runArtisanCaptured('pinpoint:report');

    expect($output)->toContain('NEEDS WORK')
        ->toContain('(GOOD)');
});

test('json includes health verdict and reason when composite on', function () {
    config()->set('pinpoint.composite_tier', true);

    seedRoute('api.n1-route', 50, 5, 4096);

    $buffer = new BufferedOutput;
    Artisan::call('pinpoint:report --json', [], $buffer);

    $payload = json_decode($buffer->fetch(), true);
    $route = collect($payload['routes'])->firstWhere('route', 'api.n1-route');

    expect($route['health'])->toBe('needs_work')
        ->and($route['health_reason'])->toContain('N+1 repeats: 5');
});
