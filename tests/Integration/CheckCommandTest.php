<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function () {
    DB::table('pinpoint_requests')->truncate();
    DB::table('pinpoint_queries')->truncate();
    DB::table('pinpoint_summaries')->truncate();
});

function insertRequest(array $overrides = []): int
{
    return DB::table('pinpoint_requests')->insertGetId(array_merge([
        'route_name' => 'api.orders',
        'method' => 'GET',
        'path' => 'api/orders',
        'duration_ms' => 100,
        'query_count' => 5,
        'query_time_ms' => 10,
        'has_n_plus_one' => false,
        'created_at' => now(),
    ], $overrides));
}

function runCheck(array $parameters = []): string
{
    return runArtisanCaptured('pinpoint:check', $parameters);
}

test('passes when there are no violations', function () {
    insertRequest();

    $this->artisan('pinpoint:check --fail-on-n1 --max-queries=20')
        ->assertSuccessful();
});

test('fails on n plus one and reports the exact offending query', function () {
    $id = insertRequest();

    DB::table('pinpoint_queries')->insert([
        ['request_id' => $id, 'sql_fingerprint' => md5('select * from orders where user_id = ?'), 'sql' => 'select * from orders where user_id = ?', 'time_ms' => 10, 'caller_file' => 'app/Models/Order.php', 'caller_line' => 12, 'created_at' => now()],
        ['request_id' => $id, 'sql_fingerprint' => md5('select * from orders where user_id = ?'), 'sql' => 'select * from orders where user_id = ?', 'time_ms' => 10, 'caller_file' => 'app/Models/Order.php', 'caller_line' => 12, 'created_at' => now()],
        ['request_id' => $id, 'sql_fingerprint' => md5('select * from orders where user_id = ?'), 'sql' => 'select * from orders where user_id = ?', 'time_ms' => 10, 'caller_file' => 'app/Models/Order.php', 'caller_line' => 12, 'created_at' => now()],
    ]);

    $output = runCheck(['--fail-on-n1' => true]);

    expect($output)
        ->toContain('select * from orders where user_id = ?')
        ->toContain('app/Models/Order.php:12')
        ->toContain('x3');
});

test('does not fail on n plus one when the flag is absent', function () {
    $id = insertRequest();

    DB::table('pinpoint_queries')->insert([
        ['request_id' => $id, 'sql_fingerprint' => 'abc', 'sql' => 'select 1', 'time_ms' => 1, 'caller_file' => null, 'caller_line' => null, 'created_at' => now()],
    ]);

    $this->artisan('pinpoint:check')->assertSuccessful();
});

test('fails when query count exceeds the budget', function () {
    insertRequest(['query_count' => 25]);

    $this->artisan('pinpoint:check --max-queries=20')
        ->assertExitCode(1);

    expect(runCheck(['--max-queries' => 20]))->toContain('25');
});

test('fails when duration exceeds the budget', function () {
    insertRequest(['duration_ms' => 5000]);

    $this->artisan('pinpoint:check --max-duration-ms=1000')
        ->assertExitCode(1);
});

test('only checks requests within the since window', function () {
    insertRequest(['query_count' => 25, 'created_at' => now()->subMinutes(60)]);
    insertRequest(['query_count' => 5, 'created_at' => now()]);

    // Old violation is outside the window — must not fail (and the gate has
    // in-window data, so the empty-window fail-closed rule doesn't apply).
    $this->artisan('pinpoint:check --max-queries=20 --since=30')
        ->assertSuccessful();
});

test('fails closed when there is no data in the window', function () {
    insertRequest(['created_at' => now()->subHours(2)]);

    $output = runCheck(['--fail-on-n1' => true, '--since' => '30']);

    // A gate that checked nothing must not report a green build.
    expect($output)->toContain('No requests recorded');

    $this->artisan('pinpoint:check --fail-on-n1 --since=30')->assertFailed();
});

test('allow-empty restores the pass-on-empty behavior explicitly', function () {
    insertRequest(['created_at' => now()->subHours(2)]);

    $output = runCheck(['--fail-on-n1' => true, '--since' => '30', '--allow-empty' => true]);

    expect($output)->toContain('--allow-empty');

    $this->artisan('pinpoint:check --fail-on-n1 --since=30 --allow-empty')->assertSuccessful();
});

test('json empty-window failure is machine readable', function () {
    $buffer = new BufferedOutput;
    Artisan::call('pinpoint:check --json --since=30', [], $buffer);

    $payload = json_decode($buffer->fetch(), true);

    expect($payload['passed'])->toBeFalse()
        ->and($payload['meta']['requests'])->toBe(0)
        ->and($payload['meta']['empty'])->toBeTrue()
        ->and($payload['violations'])->toBe([]);
});

test('check --json-to writes the payload to a file and prints its location', function () {
    $relative = 'storage/pinpoint-test/check.json';
    $absolute = base_path($relative);

    @unlink($absolute);

    $output = runCheck(['--json-to' => $relative]);

    expect($output)->toContain('JSON written to '.$absolute);

    $payload = json_decode((string) file_get_contents($absolute), true);

    // Empty window → the gate fails closed, same payload as --json.
    expect($payload['passed'])->toBeFalse()
        ->and($payload['meta']['requests'])->toBe(0)
        ->and($payload['meta']['empty'])->toBeTrue();

    @unlink($absolute);
});

test('json output is machine readable', function () {
    $id = insertRequest(['query_count' => 25]);

    $buffer = new BufferedOutput;
    Artisan::call('pinpoint:check --max-queries=20 --json', [], $buffer);

    $payload = json_decode($buffer->fetch(), true);

    expect($payload['passed'])->toBeFalse()
        ->and($payload['meta']['requests'])->toBe(1)
        ->and($payload['violations'][0]['type'])->toBe('query_budget')
        ->and($payload['violations'][0]['route'])->toBe('api.orders');
});

test('n plus one respects the repeat threshold from config', function () {
    $id = insertRequest();
    config()->set('pinpoint.n_plus_one_repeat_threshold', 5);

    DB::table('pinpoint_queries')->insert([
        ['request_id' => $id, 'sql_fingerprint' => 'abc', 'sql' => 'select 1', 'time_ms' => 1, 'caller_file' => null, 'caller_line' => null, 'created_at' => now()],
        ['request_id' => $id, 'sql_fingerprint' => 'abc', 'sql' => 'select 1', 'time_ms' => 1, 'caller_file' => null, 'caller_line' => null, 'created_at' => now()],
        ['request_id' => $id, 'sql_fingerprint' => 'abc', 'sql' => 'select 1', 'time_ms' => 1, 'caller_file' => null, 'caller_line' => null, 'created_at' => now()],
    ]);

    $this->artisan('pinpoint:check --fail-on-n1')->assertSuccessful();
});

test('rejects invalid inputs', function () {
    $this->artisan('pinpoint:check --since=0')->assertFailed();
});
