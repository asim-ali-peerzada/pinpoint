<?php

use AsimAli\Pinpoint\Internal\Recorder;
use AsimAli\Pinpoint\Internal\SuggestionBuilder;
use AsimAli\Pinpoint\Tests\Fixtures\CloseoutPackage;
use AsimAli\Pinpoint\Tests\Fixtures\Node;
use AsimAli\Pinpoint\Tests\Fixtures\Stage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function () {
    app(Recorder::class)->reset();

    Schema::create('closeout_packages', function ($table) {
        $table->id();
    });
    Schema::create('stages', function ($table) {
        $table->id();
        $table->foreignId('closeout_package_id');
    });
    Schema::create('photos', function ($table) {
        $table->id();
        $table->foreignId('stage_id');
    });
});

test('suggestion builder chains nested relations', function () {
    $builder = app(SuggestionBuilder::class);

    $chains = $builder->build([
        ['model' => CloseoutPackage::class, 'relation' => 'stages', 'caller_file' => 'app/Services/Foo.php', 'caller_line' => 10],
        ['model' => Stage::class, 'relation' => 'photos', 'caller_file' => null, 'caller_line' => null],
    ]);

    expect($chains)->toHaveCount(1)
        ->and($chains[0]['model'])->toBe(CloseoutPackage::class)
        ->and($chains[0]['relations'])->toBe('stages.photos')
        ->and($chains[0]['caller_file'])->toBe('app/Services/Foo.php');
});

test('suggestion builder does not chain unrelated models', function () {
    $builder = app(SuggestionBuilder::class);

    $chains = $builder->build([
        ['model' => CloseoutPackage::class, 'relation' => 'stages', 'caller_file' => null, 'caller_line' => null],
        ['model' => CloseoutPackage::class, 'relation' => 'stages', 'caller_file' => null, 'caller_line' => null],
    ]);

    expect($chains)->toHaveCount(1)
        ->and($chains[0]['relations'])->toBe('stages');
});

test('lazy load violations are persisted with caller on flush', function () {
    // Two packages — Laravel only flags lazy-loading violations on
    // collections with more than one item (a single lazy load isn't an N+1).
    DB::table('closeout_packages')->insert(['id' => 1]);
    DB::table('closeout_packages')->insert(['id' => 2]);
    DB::table('stages')->insert(['id' => 1, 'closeout_package_id' => 1]);
    DB::table('stages')->insert(['id' => 2, 'closeout_package_id' => 2]);

    $this->get('/pinpoint-suggestion');

    $lazyLoads = DB::table('pinpoint_lazy_loads')->get();

    expect($lazyLoads)->toHaveCount(2)
        ->and($lazyLoads->pluck('relation')->all())->toBe(['stages', 'stages'])
        ->and($lazyLoads->first()->model)->toBe(CloseoutPackage::class)
        ->and($lazyLoads->first()->caller_file)->toBeNull()
        ->and($lazyLoads->first()->caller_line)->toBeNull();
});

test('caller file and line are persisted when provided to the recorder', function () {
    $recorder = app(Recorder::class);

    $recorder->recordLazyLoad(CloseoutPackage::class, 'stages', ['file' => 'app/Services/Foo.php', 'line' => 42]);
    $recorder->flush([
        'route_name' => 'api.test',
        'method' => 'GET',
        'path' => 'api/test',
        'duration_ms' => 10,
    ]);

    $this->assertDatabaseHas('pinpoint_lazy_loads', [
        'model' => CloseoutPackage::class,
        'relation' => 'stages',
        'caller_file' => 'app/Services/Foo.php',
        'caller_line' => 42,
    ]);
});

test('report drill-down shows an actionable eager-load suggestion', function () {
    $requestId = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.packages', 'method' => 'GET', 'path' => 'api/packages',
        'duration_ms' => 5000, 'query_count' => 2, 'query_time_ms' => 100,
        'has_n_plus_one' => true, 'created_at' => now(),
    ]);

    DB::table('pinpoint_lazy_loads')->insert([
        ['request_id' => $requestId, 'model' => CloseoutPackage::class, 'relation' => 'stages', 'caller_file' => 'app/Models/CloseoutPackage.php', 'caller_line' => 10, 'created_at' => now()],
        ['request_id' => $requestId, 'model' => Stage::class, 'relation' => 'photos', 'caller_file' => null, 'caller_line' => null, 'created_at' => now()],
    ]);

    $buffer = new BufferedOutput;
    Artisan::call('pinpoint:report --route=api.packages', [], $buffer);

    $output = $buffer->fetch();

    expect($output)
        ->toContain('AsimAli\Pinpoint\Tests\Fixtures\CloseoutPackage')
        ->toContain('stages.photos')
        ->toContain('Suggested fix: AsimAli\Pinpoint\Tests\Fixtures\CloseoutPackage::with(\'stages.photos\')');
});

test('check json includes suggestions for lazy-load violations', function () {
    $requestId = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.packages', 'method' => 'GET', 'path' => 'api/packages',
        'duration_ms' => 100, 'query_count' => 2, 'query_time_ms' => 10,
        'has_n_plus_one' => true, 'created_at' => now(),
    ]);

    DB::table('pinpoint_lazy_loads')->insert([
        ['request_id' => $requestId, 'model' => CloseoutPackage::class, 'relation' => 'stages', 'caller_file' => 'app/Models/CloseoutPackage.php', 'caller_line' => 10, 'created_at' => now()],
        ['request_id' => $requestId, 'model' => Stage::class, 'relation' => 'photos', 'caller_file' => null, 'caller_line' => null, 'created_at' => now()],
    ]);

    $buffer = new BufferedOutput;
    Artisan::call('pinpoint:check --fail-on-n1 --json', [], $buffer);

    $payload = json_decode($buffer->fetch(), true);

    expect($payload['passed'])->toBeFalse()
        ->and($payload['violations'][0]['suggestions'][0]['relations'])->toBe('stages.photos')
        ->and($payload['violations'][0]['suggestions'][0]['suggested'])->toBe(CloseoutPackage::class.'::with(\'stages.photos\')');
});

test('report does not chain violations from different requests', function () {
    // Request 1: only CloseoutPackage->stages was lazy-loaded.
    $requestOne = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.packages', 'method' => 'GET', 'path' => 'api/packages',
        'duration_ms' => 100, 'query_count' => 1, 'query_time_ms' => 10,
        'has_n_plus_one' => true, 'created_at' => now(),
    ]);
    DB::table('pinpoint_lazy_loads')->insert([
        ['request_id' => $requestOne, 'model' => CloseoutPackage::class, 'relation' => 'stages', 'caller_file' => null, 'caller_line' => null, 'created_at' => now()],
    ]);

    // Request 2: only Stage->photos was lazy-loaded. Different request, so
    // the chain stages.photos must NOT be suggested.
    $requestTwo = DB::table('pinpoint_requests')->insertGetId([
        'route_name' => 'api.packages', 'method' => 'GET', 'path' => 'api/packages',
        'duration_ms' => 100, 'query_count' => 1, 'query_time_ms' => 10,
        'has_n_plus_one' => true, 'created_at' => now(),
    ]);
    DB::table('pinpoint_lazy_loads')->insert([
        ['request_id' => $requestTwo, 'model' => Stage::class, 'relation' => 'photos', 'caller_file' => null, 'caller_line' => null, 'created_at' => now()],
    ]);

    $buffer = new BufferedOutput;
    Artisan::call('pinpoint:report --route=api.packages', [], $buffer);

    $output = $buffer->fetch();

    expect($output)->not->toContain('stages.photos');
});

test('self-referential relations do not infinitely chain', function () {
    Schema::create('nodes', function ($table) {
        $table->id();
        $table->foreignId('parent_id')->nullable();
    });

    $builder = app(SuggestionBuilder::class);

    $chains = $builder->build([
        ['model' => Node::class, 'relation' => 'parent', 'caller_file' => null, 'caller_line' => null],
        ['model' => Node::class, 'relation' => 'parent', 'caller_file' => null, 'caller_line' => null],
    ]);

    expect($chains)->toHaveCount(1)
        ->and($chains[0]['relations'])->toBe('parent');
});
