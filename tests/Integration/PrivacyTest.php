<?php

use AsimAli\Pinpoint\Internal\Recorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('email');
        $table->string('password');
    });

    DB::table('users')->insert(['email' => 'admin@example.com', 'password' => 'SuperSecret123!']);

    // The fixture insert above fires QueryExecuted and lands in the
    // recorder — drop it so only the request under test is flushed.
    app(Recorder::class)->reset();
});

/**
 * Privacy protection: raw SQL bindings must never be persisted to the database.
 * Only the parameterized query shape and a one-way cryptographic hash are stored.
 */
test('sensitive bindings are never persisted, only hashed', function () {
    $this->get('/pinpoint-sensitive');

    $rows = DB::table('pinpoint_queries')->get();

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        // The raw bound values must not appear anywhere in storage.
        expect($row->sql)->not->toContain('admin@example.com')
            ->not->toContain('SuperSecret123!');
        expect((string) $row->bindings_hash)->not->toContain('admin@example.com')
            ->not->toContain('SuperSecret123!');
    }
});

test('bindings hash is deterministic for the same values', function () {
    $this->get('/pinpoint-sensitive');
    $this->get('/pinpoint-sensitive');

    $hashes = DB::table('pinpoint_queries')->pluck('bindings_hash');

    expect($hashes)->toHaveCount(2)
        ->and($hashes[0])->toBe($hashes[1])
        ->and(strlen((string) $hashes[0]))->toBeGreaterThan(10);
});

test('sensitive values never leak into the JSON report', function () {
    $this->get('/pinpoint-sensitive');

    $payload = json_decode(runArtisanCaptured('pinpoint:report', ['--json' => true]), true);
    $serialized = json_encode($payload, JSON_THROW_ON_ERROR);

    expect($serialized)->not->toContain('admin@example.com')
        ->not->toContain('SuperSecret123!');
});

test('sensitive values never leak into the CLI report', function () {
    $this->get('/pinpoint-sensitive');

    $output = runArtisanCaptured('pinpoint:report', ['--route' => 'GET pinpoint-sensitive']);

    expect($output)->not->toContain('admin@example.com')
        ->not->toContain('SuperSecret123!');
});
