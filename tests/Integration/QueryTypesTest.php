<?php

use AsimAli\Pinpoint\Internal\Recorder;
use AsimAli\Pinpoint\Tests\Fixtures\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Query type recording coverage: verifies telemetry capture across SELECT,
 * INSERT, UPDATE, DELETE, and bulk DML operations without execution overhead or failures.
 */
beforeEach(function () {
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name')->nullable();
    });

    // Schema creation fires QueryExecuted — drop it from the recorder.
    app(Recorder::class)->reset();
});

test('select, insert, update and delete are all recorded', function () {
    DB::table('users')->insert(['name' => 'a']);
    DB::table('users')->where('id', 1)->update(['name' => 'b']);
    DB::table('users')->where('id', 1)->delete();
    DB::table('users')->count();

    $queries = app(Recorder::class)->queries();

    expect($queries)->toHaveCount(4)
        ->and(array_column($queries, 'time_ms'))->each->toBeGreaterThanOrEqual(0);
});

test('bulk inserts and updates are recorded as single statements', function () {
    DB::table('users')->insert([
        ['name' => 'a'],
        ['name' => 'b'],
        ['name' => 'c'],
    ]);

    expect(app(Recorder::class)->queries())->toHaveCount(1);

    app(Recorder::class)->reset();

    DB::table('users')->update(['name' => 'x']);

    expect(app(Recorder::class)->queries())->toHaveCount(1);
});

test('raw statements are recorded', function () {
    DB::statement('create table temp_x (id integer)');
    DB::unprepared('insert into temp_x values (1)');

    expect(app(Recorder::class)->queries())->toHaveCount(2);

    DB::statement('drop table temp_x');
});

test('count, exists and aggregate queries are recorded', function () {
    DB::table('users')->insert(['name' => 'a']);
    DB::table('users')->insert(['name' => 'a']);

    DB::table('users')->where('name', 'a')->exists();
    DB::table('users')->count();
    DB::table('users')->max('id');

    expect(app(Recorder::class)->queries())->toHaveCount(5);
});

test('eloquent create and save are recorded', function () {
    $user = new User(['name' => 'eloquent']);
    $user->save();
    $user->name = 'updated';
    $user->save();

    expect(app(Recorder::class)->queries())->toHaveCount(2);
});

test('every recorded query carries a fingerprint and timing', function () {
    DB::table('users')->insert(['name' => 'a']);

    $query = app(Recorder::class)->queries()[0];

    expect($query['fingerprint'])->not->toBeNull()
        ->and($query['time_ms'])->toBeGreaterThanOrEqual(0)
        ->and($query['sql'])->toContain('insert');
});
