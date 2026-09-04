<?php

use AsimAli\Pinpoint\Internal\Recorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Database transaction safety: verifies that queries executed inside transactions
 * are recorded without modifying rollback, commit, or savepoint semantics.
 */
beforeEach(function () {
    Schema::create('accounts', function ($table) {
        $table->id();
        $table->integer('balance')->default(0);
    });

    // Schema creation fires QueryExecuted — drop it from the recorder.
    app(Recorder::class)->reset();
});

test('queries inside a successful transaction are recorded', function () {
    DB::transaction(function () {
        DB::table('accounts')->insert(['balance' => 100]);
        DB::table('accounts')->insert(['balance' => 200]);
    });

    expect(app(Recorder::class)->queries())->toHaveCount(2);
});

test('queries inside a rolled-back transaction are still recorded', function () {
    try {
        DB::transaction(function () {
            DB::table('accounts')->insert(['balance' => 100]);
            DB::transaction(fn () => DB::table('accounts')->insert(['balance' => 200]));

            throw new RuntimeException('roll me back');
        });
    } catch (RuntimeException) {
        // Laravel re-throws after rolling back — expected.
    }

    // Snapshot before asserting: the count() check below is itself a query
    // the recorder would capture.
    $queries = app(Recorder::class)->queries();

    // Laravel rolled everything back — no rows survive.
    expect(DB::table('accounts')->count())->toBe(0)
        // ...but the queries executed, so Pinpoint recorded them.
        ->and($queries)->toHaveCount(2);
});

test('exception inside a transaction does not break the recorder', function () {
    try {
        DB::transaction(function () {
            DB::table('accounts')->insert(['balance' => 100]);

            throw new RuntimeException('mid-transaction');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(app(Recorder::class)->queries())->toHaveCount(1);

    // Recording keeps working after the failure.
    DB::table('accounts')->insert(['balance' => 50]);

    expect(app(Recorder::class)->queries())->toHaveCount(2);
});

test('nested transactions do not interfere with recording', function () {
    DB::transaction(function () {
        DB::table('accounts')->insert(['balance' => 100]);

        DB::transaction(function () {
            DB::table('accounts')->insert(['balance' => 200]);
        });
    });

    // Snapshot before asserting: the count() check below is itself a query
    // the recorder would capture.
    $queries = app(Recorder::class)->queries();

    expect(DB::table('accounts')->count())->toBe(2)
        ->and($queries)->toHaveCount(2);
});

test('pinpoint never alters transaction outcomes', function () {
    $committed = DB::transaction(function () {
        DB::table('accounts')->insert(['balance' => 42]);

        return 'done';
    });

    expect($committed)->toBe('done')
        ->and(DB::table('accounts')->value('balance'))->toBe(42);
});
