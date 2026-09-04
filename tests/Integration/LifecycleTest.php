<?php

use AsimAli\Pinpoint\Internal\Recorder;
use Illuminate\Support\Facades\DB;

/**
 * Request lifecycle and exception safety: verifies that standard, redirected,
 * 404, and uncaught exception requests are captured with isolated telemetry.
 */
beforeEach(function () {
    DB::table('pinpoint_requests')->truncate();
    DB::table('pinpoint_queries')->truncate();
    // Fixture writes above fire QueryExecuted — drop them so only the
    // request under test is flushed.
    app(Recorder::class)->reset();
});

test('a normal request is recorded', function () {
    $this->get('/pinpoint-test');

    expect(DB::table('pinpoint_requests')->count())->toBe(1)
        ->and(DB::table('pinpoint_requests')->value('path'))->toBe('pinpoint-test');
});

test('a 404 request is recorded with its path', function () {
    $this->get('/does-not-exist');

    expect(DB::table('pinpoint_requests')->count())->toBe(1)
        ->and(DB::table('pinpoint_requests')->value('path'))->toBe('does-not-exist');
});

test('a redirect request is recorded', function () {
    $this->get('/pinpoint-redirect');

    expect(DB::table('pinpoint_requests')->count())->toBe(1)
        ->and(DB::table('pinpoint_requests')->value('path'))->toBe('pinpoint-redirect');
});

test('a validation failure (422) is recorded', function () {
    $this->get('/pinpoint-validation');

    expect(DB::table('pinpoint_requests')->count())->toBe(1)
        ->and(DB::table('pinpoint_requests')->value('path'))->toBe('pinpoint-validation');
});

test('an authorization failure (403) is recorded', function () {
    $this->get('/pinpoint-forbidden');

    expect(DB::table('pinpoint_requests')->count())->toBe(1)
        ->and(DB::table('pinpoint_requests')->value('path'))->toBe('pinpoint-forbidden');
});

test('a thrown exception records the queries that ran before the throw', function () {
    $this->get('/pinpoint-throw')->assertStatus(500);

    $request = DB::table('pinpoint_requests')->first();

    expect($request)->not->toBeNull()
        ->and($request->query_count)->toBe(1)
        ->and(DB::table('pinpoint_queries')->count())->toBe(1)
        ->and(DB::table('pinpoint_queries')->value('sql'))->toBe('select 1');
});

test('exception does not corrupt state and the recorder is clean afterwards', function () {
    $this->get('/pinpoint-throw')->assertStatus(500);

    // The request's flush already reset the recorder...
    expect(app(Recorder::class)->queries())->toBe([]);

    // ...and recording works for the next (simulated) request.
    DB::select('select 1');

    expect(app(Recorder::class)->queries())->toHaveCount(1)
        ->and(app(Recorder::class)->queries()[0]['sql'])->toBe('select 1');
});

test('flush does not record its own insert writes as queries', function () {
    $recorder = app(Recorder::class);
    $recorder->reset();

    // Record one real query, then flush — the flush's own inserts into
    // pinpoint_requests/queries must not be captured back into the log.
    $recorder->recordQuery([
        'sql' => 'select 1',
        'fingerprint' => md5('select 1'),
        'bindings_hash' => null,
        'time_ms' => 1,
        'caller' => null,
    ]);

    $recorder->flush([
        'route_name' => 'api.selfcheck',
        'method' => 'GET',
        'path' => 'api/selfcheck',
        'duration_ms' => 10,
    ]);

    // The flush inserts into pinpoint_requests/queries/lazy_loads must not
    // appear as recorded queries for the request itself.
    $stored = DB::table('pinpoint_queries')
        ->where('sql', 'like', '%pinpoint_%')
        ->count();

    expect($stored)->toBe(0);
});

test('locate shows critical tier reason, not phantom N+1 x1', function () {
    DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.heavy',
        'method' => 'GET',
        'path' => 'api/heavy',
        'duration_ms' => 5000,
        'query_count' => 0,
        'query_time_ms' => 0,
        'has_n_plus_one' => false,
        'peak_memory_kb' => null,
        'created_at' => now(),
    ]);

    $output = runArtisanCaptured('pinpoint:report', []);

    expect($output)->toContain('critical tier');
    expect($output)->not->toContain('N+1 x1');
});
