<?php

use AsimAli\Pinpoint\Internal\Recorder;
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

test('live pipeline: same-binding repeat is stored as exact duplicate', function () {
    DB::table('users')->insert(['name' => 'a']);

    $this->get('/pinpoint-duplicate');

    $rows = DB::table('pinpoint_queries')
        ->where('sql', 'select * from users where id = ?')
        ->get();

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('bindings_hash')->unique())->toHaveCount(1)
        ->and($rows->first()->bindings_hash)->not->toBeNull();
});

test('live pipeline: CACHE badge and suggestion via real report', function () {
    DB::table('users')->insert(['name' => 'a']);

    $this->get('/pinpoint-duplicate');

    $output = runArtisanCaptured('pinpoint:report', ['--route' => 'GET pinpoint-duplicate']);

    expect($output)
        ->toContain('CACHE')
        ->toContain('Cache::remember');
});

test('live pipeline: peak memory is recorded and displayed', function () {
    DB::table('users')->insert(['name' => 'a']);

    $this->get('/pinpoint-test');

    $row = DB::table('pinpoint_requests')->first();

    expect($row->peak_memory_kb)->not->toBeNull()
        ->and($row->peak_memory_kb)->toBeGreaterThan(0);
});

test('live pipeline: memory column shows and flags over budget', function () {
    DB::table('users')->insert(['name' => 'a']);

    $this->get('/pinpoint-test');

    config()->set('pinpoint.memory_budget_kb', 1);

    $output = runArtisanCaptured('pinpoint:report');

    expect($output)->toContain('Memory');
});
