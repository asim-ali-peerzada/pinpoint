<?php

use AsimAli\Pinpoint\Internal\Recorder;
use AsimAli\Pinpoint\Tests\Fixtures\CloseoutPackage;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    app(Recorder::class)->reset();
    DB::table('pinpoint_requests')->truncate();
    DB::table('pinpoint_queries')->truncate();
    DB::table('pinpoint_lazy_loads')->truncate();
});

test('summary prints a locate block for flagged routes with caller', function () {
    $id = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders',
        'duration_ms' => 9000, 'query_count' => 14, 'query_time_ms' => 100,
        'has_n_plus_one' => true, 'created_at' => now(),
    ]);
    DB::table('pinpoint_lazy_loads')->insert([
        ['request_id' => $id, 'model' => CloseoutPackage::class, 'relation' => 'stages', 'caller_file' => 'app/Http/Controllers/OrderController.php', 'caller_line' => 41, 'created_at' => now()],
    ]);

    $output = runArtisanCaptured('pinpoint:report');

    expect($output)
        ->toContain('Locate')
        ->toContain('api.orders')
        ->toContain('app/Http/Controllers/OrderController.php:41');
});

test('locate block caps at 5 offenders with a footer hint', function () {
    for ($i = 1; $i <= 8; $i++) {
        DB::table('pinpoint_requests')->insert([
            'route_name' => 'api.slow'.$i, 'method' => 'GET', 'path' => 'api/slow'.$i,
            'duration_ms' => 9000, 'query_count' => 1, 'query_time_ms' => 100,
            'has_n_plus_one' => false, 'created_at' => now(),
        ]);
    }

    $output = runArtisanCaptured('pinpoint:report');

    expect($output)
        ->toContain('Locate')
        ->toContain('more route(s)')
        ->toContain('--route=');
});

test('caller link renders the editor URI scheme', function () {
    config()->set('pinpoint.editor', 'phpstorm');

    $id = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.orders', 'method' => 'GET', 'path' => 'api/orders',
        'duration_ms' => 9000, 'query_count' => 3, 'query_time_ms' => 100,
        'has_n_plus_one' => true, 'created_at' => now(),
    ]);
    DB::table('pinpoint_lazy_loads')->insert([
        ['request_id' => $id, 'model' => CloseoutPackage::class, 'relation' => 'stages', 'caller_file' => 'app/Http/Controllers/OrderController.php', 'caller_line' => 41, 'created_at' => now()],
    ]);

    $output = runArtisanCaptured('pinpoint:report --route=api.orders');

    // OSC 8 href is rendered by Termwind as href=... — verify the scheme is present.
    expect($output)->toContain('phpstorm://open?file');
});

test('capturesCaller honors explicit staging opt-in', function () {
    $recorder = app(Recorder::class);
    $app = app();
    $original = $app->environment();

    try {
        $app->detectEnvironment(fn () => 'production');
        config()->set('pinpoint.capture_caller', true);

        expect($recorder->capturesCaller())->toBeTrue();
    } finally {
        $app->detectEnvironment(fn () => $original);
        config()->set('pinpoint.capture_caller', null);
    }
});
