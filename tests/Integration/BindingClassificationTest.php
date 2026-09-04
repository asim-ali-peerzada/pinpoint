<?php

use AsimAli\Pinpoint\Internal\Recorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verifies SQL binding normalization and query classification edge cases:
 * - Integer 1 and string '1' cast identically to avoid false variance.
 * - NULL values remain distinct from empty strings.
 * - Empty bindings produce a null hash (unknown classification).
 * - Distinguishes identical bindings (CACHE candidates) from varying bindings (N+1 loops).
 */
beforeEach(function () {
    app(Recorder::class)->reset();

    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
    });

    DB::table('users')->insert(['name' => 'a']);

    app(Recorder::class)->reset();
});

test('int 1 and string "1" produce the same bindings hash', function () {
    DB::select('select * from users where id = ?', [1]);
    DB::select('select * from users where id = ?', ['1']);
    DB::select('select * from users where id = ?', [1]);

    $groups = app(Recorder::class)->classifyRepeatGroups();

    expect($groups)->not->toBeEmpty()
        ->and(array_values($groups)[0]['type'])->toBe('duplicate');
});

test('0 and "0", true and "1" normalize identically', function () {
    DB::select('select * from users where id = ?', [0]);
    DB::select('select * from users where id = ?', ['0']);
    DB::select('select * from users where id = ?', [0]);

    $groups = app(Recorder::class)->classifyRepeatGroups();
    expect(array_values($groups)[0]['type'])->toBe('duplicate');

    app(Recorder::class)->reset();

    DB::select('select * from users where id = ?', [true]);
    DB::select('select * from users where id = ?', ['1']);
    DB::select('select * from users where id = ?', [true]);

    $groups = app(Recorder::class)->classifyRepeatGroups();
    expect(array_values($groups)[0]['type'])->toBe('duplicate');
});

test('null bindings are distinct from zero', function () {
    DB::select('select * from users where id = ?', [null]);
    DB::select('select * from users where id = ?', [0]);
    DB::select('select * from users where id = ?', [null]);

    $groups = app(Recorder::class)->classifyRepeatGroups();

    // Two distinct binding sets (null vs "0") → N+1 pattern, not duplicate.
    expect(array_values($groups)[0]['type'])->toBe('n_plus_one');
});

test('negative numbers and floats normalize as strings', function () {
    DB::select('select * from users where id = ?', [-1]);
    DB::select('select * from users where id = ?', ['-1']);
    DB::select('select * from users where id = ?', [-1]);

    expect(array_values(app(Recorder::class)->classifyRepeatGroups())[0]['type'])->toBe('duplicate');

    app(Recorder::class)->reset();

    DB::select('select * from users where id = ?', [1.5]);
    DB::select('select * from users where id = ?', ['1.5']);
    DB::select('select * from users where id = ?', [1.5]);

    expect(array_values(app(Recorder::class)->classifyRepeatGroups())[0]['type'])->toBe('duplicate');
});

test('unicode and long string bindings classify as duplicates when identical', function () {
    $emoji = 'héllo-👋-日本語';
    DB::select('select * from users where name = ?', [$emoji]);
    DB::select('select * from users where name = ?', [$emoji]);
    DB::select('select * from users where name = ?', [$emoji]);

    expect(array_values(app(Recorder::class)->classifyRepeatGroups())[0]['type'])->toBe('duplicate');
});

test('varying values of the same shape classify as n_plus_one', function () {
    DB::select('select * from users where id = ?', [1]);
    DB::select('select * from users where id = ?', [2]);
    DB::select('select * from users where id = ?', [3]);

    expect(array_values(app(Recorder::class)->classifyRepeatGroups())[0]['type'])->toBe('n_plus_one');
});

test('identical IN lists are duplicates, different IN lists are n_plus_one', function () {
    DB::table('users')->whereIn('id', [1, 2, 3])->get();
    DB::table('users')->whereIn('id', [1, 2, 3])->get();
    DB::table('users')->whereIn('id', [1, 2, 3])->get();

    $groups = app(Recorder::class)->classifyRepeatGroups();

    expect($groups)->not->toBeEmpty()
        ->and(array_values($groups)[0]['type'])->toBe('duplicate');

    app(Recorder::class)->reset();

    DB::table('users')->whereIn('id', [1, 2, 3])->get();
    DB::table('users')->whereIn('id', [4, 5, 6])->get();
    DB::table('users')->whereIn('id', [7, 8, 9])->get();

    $groups = app(Recorder::class)->classifyRepeatGroups();

    expect($groups)->not->toBeEmpty()
        ->and(array_values($groups)[0]['type'])->toBe('n_plus_one');
});

test('no-binding queries classify as unknown (conservative), never duplicate', function () {
    DB::select('select 1');
    DB::select('select 1');
    DB::select('select 1');

    $groups = app(Recorder::class)->classifyRepeatGroups();

    expect($groups)->not->toBeEmpty()
        ->and(array_values($groups)[0]['type'])->toBe('unknown');
});

test('duplicate and n_plus_one groups coexist independently in one request', function () {
    DB::select('select * from users where id = ?', [1]);
    DB::select('select * from users where id = ?', [1]);
    DB::select('select * from users where id = ?', [1]);

    DB::select('select * from users where name = ?', ['a']);
    DB::select('select * from users where name = ?', ['b']);
    DB::select('select * from users where name = ?', ['c']);

    $groups = app(Recorder::class)->classifyRepeatGroups();

    $types = array_column(array_values($groups), 'type');

    expect($types)->toContain('duplicate')
        ->and($types)->toContain('n_plus_one');
});
