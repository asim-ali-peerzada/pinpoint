<?php

use AsimAli\Pinpoint\Internal\Recorder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\BufferedOutput;

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

test('CI loop: recorded lazy-load N+1 is caught by pinpoint:check with caller', function () {
    DB::table('users')->insert(['name' => 'a']);
    DB::table('users')->insert(['name' => 'b']);

    // Simulate the CI test suite: a route with an Eloquent N+1.
    $this->get('/pinpoint-lazy');

    // Simulate the CI gate: check what was just recorded.
    $buffer = new BufferedOutput;
    Artisan::call('pinpoint:check --fail-on-n1 --json', [], $buffer);

    $payload = json_decode($buffer->fetch(), true);

    expect($payload['passed'])->toBeFalse()
        ->and($payload['meta']['requests'])->toBe(1)
        ->and($payload['violations'][0]['type'])->toBe('n_plus_one');
});
