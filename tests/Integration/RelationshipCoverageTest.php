<?php

use AsimAli\Pinpoint\Internal\Recorder;
use AsimAli\Pinpoint\Tests\Fixtures\Author;
use AsimAli\Pinpoint\Tests\Fixtures\Book;
use Illuminate\Support\Facades\Schema;

/**
 * Eloquent relationship coverage: verifies that un-eager-loaded relationships
 * trigger N+1 detection while eager-loaded relationships produce zero false positives.
 */
beforeEach(function () {
    app(Recorder::class)->reset();

    Schema::create('authors', function ($table) {
        $table->id();
    });
    Schema::create('books', function ($table) {
        $table->id();
        $table->boolean('hardcover')->default(false);
    });
    Schema::create('author_book', function ($table) {
        $table->foreignId('author_id');
        $table->foreignId('book_id');
    });
    Schema::create('comments', function ($table) {
        $table->id();
        $table->morphs('commentable');
        $table->string('body');
    });

    foreach (range(1, 4) as $i) {
        $author = Author::create();
        $book = Book::create(['hardcover' => $i % 2 === 0]);
        $author->books()->attach($book);
        $book->comments()->create(['body' => 'c'.$i]);
    }

    app(Recorder::class)->reset();
});

test('lazy belongsToMany access is flagged as N+1', function () {
    foreach (Author::all() as $author) {
        $author->books->count();
    }

    $recorder = app(Recorder::class);

    expect($recorder->hasNPlusOne())->toBeTrue()
        ->and($recorder->lazyLoads())->not->toBeEmpty();
});

test('lazy morphMany access is flagged as N+1', function () {
    foreach (Book::all() as $book) {
        $book->comments->count();
    }

    $recorder = app(Recorder::class);

    expect($recorder->hasNPlusOne())->toBeTrue()
        ->and($recorder->lazyLoads())->not->toBeEmpty();
});

test('eager with() on belongsToMany produces no false positive', function () {
    Author::with('books')->get();

    $recorder = app(Recorder::class);

    expect($recorder->hasNPlusOne())->toBeFalse()
        ->and($recorder->lazyLoads())->toBe([])
        ->and(count($recorder->queries()))->toBe(2);
});

test('eager with() on morphMany produces no false positive', function () {
    Book::with('comments')->get();

    $recorder = app(Recorder::class);

    expect($recorder->hasNPlusOne())->toBeFalse()
        ->and($recorder->lazyLoads())->toBe([])
        ->and(count($recorder->queries()))->toBe(2);
});

test('load() after fetching produces no false positive', function () {
    $authors = Author::all();
    $authors->load('books');

    $recorder = app(Recorder::class);

    expect($recorder->hasNPlusOne())->toBeFalse()
        ->and($recorder->lazyLoads())->toBe([]);
});

test('loadMissing() on already-loaded relations adds no queries and no false positive', function () {
    $authors = Author::with('books')->get();
    $authors->loadMissing('books');

    $recorder = app(Recorder::class);

    expect($recorder->hasNPlusOne())->toBeFalse()
        ->and($recorder->lazyLoads())->toBe([])
        ->and(count($recorder->queries()))->toBe(2);
});

test('constrained eager loading produces no false positive', function () {
    Author::with(['books' => fn ($q) => $q->where('hardcover', true)])->get();

    $recorder = app(Recorder::class);

    expect($recorder->hasNPlusOne())->toBeFalse()
        ->and($recorder->lazyLoads())->toBe([]);
});

test('withCount and has existence queries produce no false positive', function () {
    Author::withCount('books')->get();
    Author::has('books')->get();

    $recorder = app(Recorder::class);

    expect($recorder->hasNPlusOne())->toBeFalse()
        ->and($recorder->lazyLoads())->toBe([]);
});
