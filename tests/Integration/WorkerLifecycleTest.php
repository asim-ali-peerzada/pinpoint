<?php

use AsimAli\Pinpoint\Caller;
use AsimAli\Pinpoint\Internal\Recorder;
use AsimAli\Pinpoint\PinpointServiceProvider;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    app(Recorder::class)->reset();
});

test('queue worker job isolation: scoped instances reset between jobs', function () {
    DB::table('pinpoint_requests')->count();
    $job1 = app(Recorder::class);

    // Same cleanup the queue worker runs after each job.
    $this->app->forgetScopedInstances();

    $job2 = app(Recorder::class);
    DB::table('pinpoint_requests')->count();

    expect($job2)->not->toBe($job1)
        ->and($job2->queries())->toHaveCount(1);
});

test('caller capture reaches app code beyond the old 15-frame limit', function () {
    // Anchor to the package root so the test closure is inside the scanned
    // base path (Testbench's base_path() is the skeleton app, not our code).
    $packageRoot = realpath(__DIR__.'/../..');

    $captured = Caller::capture($packageRoot);

    expect($captured)->not->toBeNull()
        ->and($captured['file'])->toContain('tests');
});

test('request duration uses per-request start time', function () {
    $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 1.5;

    $provider = new ReflectionClass(PinpointServiceProvider::class);
    $method = $provider->getMethod('requestStart');
    $method->setAccessible(true);
    $instance = $provider->newInstanceWithoutConstructor();

    $start = $method->invoke($instance);

    expect(abs($start - (float) $_SERVER['REQUEST_TIME_FLOAT']))->toBeLessThan(0.001);

    unset($_SERVER['REQUEST_TIME_FLOAT']);
});

test('flush runs via the application terminating callbacks', function () {
    // Tests call $kernel->terminate() inside get(), which runs the
    // terminating callbacks — the flush must land through that path.
    $this->get('/pinpoint-test');

    expect(DB::table('pinpoint_requests')->count())->toBe(1);
});

test('multiple requests in one process never re-flush stale requests', function () {
    // Regression: Laravel's terminate() re-runs every registered callback,
    // so a per-request callback registration would re-flush old requests in
    // long-running processes (Octane, queue workers, tests). The flush must
    // be registered ONCE at boot and only flush the current request.
    $this->get('/pinpoint-test');
    $this->get('/pinpoint-test');
    $this->get('/pinpoint-test');

    expect(DB::table('pinpoint_requests')->count())->toBe(3);
});
