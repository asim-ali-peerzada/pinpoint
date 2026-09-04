<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;

use function Termwind\renderUsing;

function seedDiffRequest(string $route, int $durationMs, int $queryCount = 1): void
{
    DB::table('pinpoint_requests')->insert([
        'route_name' => $route, 'method' => 'GET', 'path' => $route,
        'duration_ms' => $durationMs, 'query_count' => $queryCount,
        'query_time_ms' => 1, 'has_n_plus_one' => false,
        'peak_memory_kb' => 4096, 'created_at' => now(),
    ]);
}

function runSnapshot(array $parameters = []): string
{
    return runArtisanCaptured('pinpoint:snapshot', $parameters);
}

function runDiff(array $parameters = []): string
{
    return runArtisanCaptured('pinpoint:diff', $parameters);
}

function runDiffCode(array $parameters = []): int
{
    $buffer = new BufferedOutput;
    $buffer->setDecorated(true);

    renderUsing($buffer);
    $code = Artisan::call('pinpoint:diff', $parameters, $buffer);
    renderUsing(null);

    return $code;
}

beforeEach(function () {
    DB::table('pinpoint_requests')->truncate();
    DB::table('pinpoint_queries')->truncate();
    DB::table('pinpoint_lazy_loads')->truncate();
    DB::table('pinpoint_baselines')->truncate();
});

test('snapshot with no requests fails with a clear message', function () {
    $output = runSnapshot(['--tag' => 'main']);

    expect($output)->toContain('No requests recorded');
});

test('snapshot saves the correct route count', function () {
    seedDiffRequest('api.orders', 100);
    seedDiffRequest('api.users', 200);

    runSnapshot(['--tag' => 'v1']);

    expect(DB::table('pinpoint_baselines')->where('tag', 'v1')->value('route_count'))->toBe(2);
});

test('snapshot with a duplicate tag overwrites instead of stacking rows', function () {
    seedDiffRequest('api.orders', 100);

    runSnapshot(['--tag' => 'main']);
    runSnapshot(['--tag' => 'main']);

    expect(DB::table('pinpoint_baselines')->where('tag', 'main')->count())->toBe(1);
});

test('snapshot --no-overwrite fails when the tag already exists', function () {
    seedDiffRequest('api.orders', 100);
    runSnapshot(['--tag' => 'main']);

    $output = runSnapshot(['--tag' => 'main', '--no-overwrite' => true]);

    expect($output)->toContain('already exists');
});

test('diff with a missing baseline tag fails and lists available tags', function () {
    $output = runDiff(['--baseline' => 'nope']);

    expect($output)->toContain('not found')
        ->and($output)->toContain('(none)');
});

test('diff --json with a missing baseline tag emits a valid error payload', function () {
    $payload = json_decode(runDiff(['--baseline' => 'nope', '--json' => true]), true);

    expect(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($payload['meta']['baseline_tag'])->toBe('nope')
        ->and($payload['meta']['error'])->toContain('not found')
        ->and($payload['routes'])->toBe([]);
});

test('diff detects a p95 regression and labels it', function () {
    seedDiffRequest('api.orders', 100);
    runSnapshot(['--tag' => 'main']);

    DB::table('pinpoint_requests')->truncate();
    seedDiffRequest('api.orders', 5000);

    $output = runDiff(['--baseline' => 'main']);

    expect($output)
        ->toContain('api.orders')
        ->toContain('REGRESSION')
        ->toContain('+4900.0%')
        ->toContain('5000ms');
});

test('diff --fail-on-regression exits with failure on a regression', function () {
    seedDiffRequest('api.orders', 100);
    runSnapshot(['--tag' => 'main']);

    DB::table('pinpoint_requests')->truncate();
    seedDiffRequest('api.orders', 5000);

    expect(runDiffCode(['--baseline' => 'main', '--fail-on-regression' => true]))->toBe(Command::FAILURE);
});

test('diff --fail-on-regression exits successfully without regressions', function () {
    seedDiffRequest('api.orders', 100);
    runSnapshot(['--tag' => 'main']);

    expect(runDiffCode(['--baseline' => 'main', '--fail-on-regression' => true]))->toBe(Command::SUCCESS);
});

test('diff --json emits the machine-readable shape', function () {
    seedDiffRequest('api.orders', 100);
    runSnapshot(['--tag' => 'main']);

    DB::table('pinpoint_requests')->truncate();
    seedDiffRequest('api.orders', 5000);

    $payload = json_decode(runDiff(['--baseline' => 'main', '--json' => true]), true);

    expect($payload['meta']['baseline_tag'])->toBe('main')
        ->and($payload['meta']['regression_count'])->toBe(1)
        ->and($payload['routes'][0]['status'])->toBe('regression')
        ->and($payload['routes'][0]['changes']['p95_delta_ms'])->toBe(4900);
});

test('diff --json-to writes the payload to a file', function () {
    seedDiffRequest('api.orders', 100);
    runSnapshot(['--tag' => 'main']);

    DB::table('pinpoint_requests')->truncate();
    seedDiffRequest('api.orders', 5000);

    $target = sys_get_temp_dir().'/pinpoint-diff-'.uniqid().'.json';

    $output = runDiff(['--baseline' => 'main', '--json-to' => $target]);

    expect($output)->toContain('JSON written')
        ->and(file_exists($target))->toBeTrue()
        ->and(json_decode(file_get_contents($target), true)['meta']['regression_count'])->toBe(1);

    @unlink($target);
});

test('diff regression details show the caller and eager-load fix', function () {
    $id = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders',
        'duration_ms' => 100, 'query_count' => 5, 'query_time_ms' => 1,
        'has_n_plus_one' => true, 'peak_memory_kb' => 4096, 'created_at' => now(),
    ]);
    DB::table('pinpoint_queries')->insert([
        ['request_id' => $id, 'sql_fingerprint' => 'fp1', 'sql' => 'select * from orders', 'bindings_hash' => 'hash-1', 'time_ms' => 1, 'caller_file' => 'app/Models/Order.php', 'caller_line' => 12, 'created_at' => now()],
        ['request_id' => $id, 'sql_fingerprint' => 'fp1', 'sql' => 'select * from orders', 'bindings_hash' => 'hash-2', 'time_ms' => 1, 'caller_file' => 'app/Models/Order.php', 'caller_line' => 12, 'created_at' => now()],
        ['request_id' => $id, 'sql_fingerprint' => 'fp1', 'sql' => 'select * from orders', 'bindings_hash' => 'hash-3', 'time_ms' => 1, 'caller_file' => 'app/Models/Order.php', 'caller_line' => 12, 'created_at' => now()],
    ]);
    runSnapshot(['--tag' => 'main']);

    DB::table('pinpoint_requests')->truncate();
    DB::table('pinpoint_queries')->truncate();
    $id = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders',
        'duration_ms' => 5000, 'query_count' => 30, 'query_time_ms' => 1,
        'has_n_plus_one' => true, 'peak_memory_kb' => 4096, 'created_at' => now(),
    ]);
    DB::table('pinpoint_queries')->insert([
        ['request_id' => $id, 'sql_fingerprint' => 'fp1', 'sql' => 'select * from orders', 'bindings_hash' => 'hash-1', 'time_ms' => 1, 'caller_file' => 'app/Http/Controllers/OrderController.php', 'caller_line' => 42, 'created_at' => now()],
        ['request_id' => $id, 'sql_fingerprint' => 'fp1', 'sql' => 'select * from orders', 'bindings_hash' => 'hash-2', 'time_ms' => 1, 'caller_file' => 'app/Http/Controllers/OrderController.php', 'caller_line' => 42, 'created_at' => now()],
        ['request_id' => $id, 'sql_fingerprint' => 'fp1', 'sql' => 'select * from orders', 'bindings_hash' => 'hash-3', 'time_ms' => 1, 'caller_file' => 'app/Http/Controllers/OrderController.php', 'caller_line' => 42, 'created_at' => now()],
        ['request_id' => $id, 'sql_fingerprint' => 'fp1', 'sql' => 'select * from orders', 'bindings_hash' => 'hash-4', 'time_ms' => 1, 'caller_file' => 'app/Http/Controllers/OrderController.php', 'caller_line' => 42, 'created_at' => now()],
        ['request_id' => $id, 'sql_fingerprint' => 'fp1', 'sql' => 'select * from orders', 'bindings_hash' => 'hash-5', 'time_ms' => 1, 'caller_file' => 'app/Http/Controllers/OrderController.php', 'caller_line' => 42, 'created_at' => now()],
    ]);

    $output = runDiff(['--baseline' => 'main']);

    expect($output)
        ->toContain('OrderController.php')
        ->toContain('N+1')
        ->toContain('Yes (x5)');
});

test('diff hides stable routes by default and shows them with --show-stable', function () {
    seedDiffRequest('api.orders', 100);
    seedDiffRequest('api.stable', 100);
    runSnapshot(['--tag' => 'main']);

    DB::table('pinpoint_requests')->truncate();
    seedDiffRequest('api.orders', 5000);
    seedDiffRequest('api.stable', 100);

    $output = runDiff(['--baseline' => 'main']);
    expect($output)
        ->toContain('api.orders')
        ->not->toContain('api.stable');

    $output = runDiff(['--baseline' => 'main', '--show-stable' => true]);
    expect($output)->toContain('api.stable');
});

test('diff shows routes added and removed since the baseline', function () {
    seedDiffRequest('api.orders', 100);
    seedDiffRequest('api.removed', 100);
    runSnapshot(['--tag' => 'main']);

    DB::table('pinpoint_requests')->truncate();
    seedDiffRequest('api.orders', 100);
    seedDiffRequest('api.brand-new', 100);

    $output = runDiff(['--baseline' => 'main', '--show-stable' => true]);

    expect($output)
        ->toContain('api.removed')
        ->toContain('REMOVED')
        ->toContain('api.brand-new')
        ->toContain('NEW');
});
