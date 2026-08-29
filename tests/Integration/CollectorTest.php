<?php

use AsimAli\Pinpoint\Internal\Recorder;
use AsimAli\Pinpoint\Pinpoint;
use AsimAli\Pinpoint\Tests\Fixtures\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    app(Recorder::class)->reset();

    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
    });

    Schema::create('posts', function ($table) {
        $table->id();
        $table->foreignId('user_id');
    });
});

test('request pipeline stores request and queries', function () {
    $this->get('/pinpoint-test');

    $this->assertDatabaseHas('pinpoint_requests', ['path' => 'pinpoint-test']);
    $this->assertDatabaseHas('pinpoint_queries', [
        'sql' => 'select 1',
    ]);
});

test('n plus one via lazy loading is flagged', function () {
    DB::table('users')->insert(['name' => 'a']);
    DB::table('users')->insert(['name' => 'b']);

    $this->get('/pinpoint-lazy');

    $this->assertDatabaseHas('pinpoint_requests', [
        'path' => 'pinpoint-lazy',
        'has_n_plus_one' => true,
    ]);
});

test('repeated fingerprint is flagged without eloquent', function () {
    $this->get('/pinpoint-raw-repeat');

    $this->assertDatabaseHas('pinpoint_requests', [
        'path' => 'pinpoint-raw-repeat',
        'has_n_plus_one' => true,
    ]);
});

test('non sampled request discards buffer', function () {
    app('config')->set('pinpoint.sample_rate', 0.0);

    $recorder = app(Recorder::class);
    $recorder->recordQuery(['sql' => 'select 1', 'fingerprint' => 'x', 'time_ms' => 1, 'caller' => null]);

    $this->get('/pinpoint-test');

    expect($recorder->queries())->toBe([]);
    $this->assertDatabaseCount('pinpoint_requests', 0);
});

test('flush failure does not break the request', function () {
    Schema::drop('pinpoint_requests');
    Schema::drop('pinpoint_queries');

    $this->get('/pinpoint-test')->assertOk();
});

test('lazy loading violation is recorded for apps with their own handler', function () {
    app(Pinpoint::class)->observeLazyLoad(User::class, 'posts');

    expect(app(Recorder::class)->lazyLoads())->toHaveCount(1);
});

test('facade provides static observeLazyLoad access', function () {
    AsimAli\Pinpoint\Facades\Pinpoint::observeLazyLoad(User::class, 'posts');

    expect(app(Recorder::class)->lazyLoads())->toHaveCount(1);
});

test('caller capture is disabled outside local and testing environments', function () {
    $recorder = app(Recorder::class);
    $app = app();
    $original = $app->environment();

    try {
        $app->detectEnvironment(fn () => 'production');
        expect($recorder->capturesCaller())->toBeFalse();
    } finally {
        $app->detectEnvironment(fn () => $original);
    }

    // CI runs with APP_ENV=testing — callers must be captured there so
    // pinpoint:check can report the exact file:line of an N+1.
    $app->detectEnvironment(fn () => 'testing');
    expect($recorder->capturesCaller())->toBeTrue();

    $app->detectEnvironment(fn () => $original);
});
