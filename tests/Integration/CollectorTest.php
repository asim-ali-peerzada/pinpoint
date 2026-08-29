<?php

namespace AsimAli\Pinpoint\Tests\Integration;

use AsimAli\Pinpoint\Pinpoint;
use AsimAli\Pinpoint\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CollectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(Pinpoint::class)->reset();

        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('posts', function ($table) {
            $table->id();
            $table->foreignId('user_id');
        });
    }
    public function test_request_pipeline_stores_request_and_queries(): void
    {
        $this->get('/pinpoint-test');

        $this->assertDatabaseHas('pinpoint_requests', ['path' => 'pinpoint-test']);
        $this->assertDatabaseHas('pinpoint_queries', [
            'sql' => 'select 1',
        ]);
    }

    public function test_n_plus_one_via_lazy_loading_is_flagged(): void
    {
        DB::table('users')->insert(['name' => 'a']);
        DB::table('users')->insert(['name' => 'b']);

        $this->get('/pinpoint-lazy');

        $this->assertDatabaseHas('pinpoint_requests', [
            'path' => 'pinpoint-lazy',
            'has_n_plus_one' => true,
        ]);
    }

    public function test_repeated_fingerprint_is_flagged_without_eloquent(): void
    {
        $this->get('/pinpoint-raw-repeat');

        $this->assertDatabaseHas('pinpoint_requests', [
            'path' => 'pinpoint-raw-repeat',
            'has_n_plus_one' => true,
        ]);
    }

    public function test_non_sampled_request_discards_buffer(): void
    {
        app('config')->set('pinpoint.sample_rate', 0.0);

        $pinpoint = app(Pinpoint::class);
        $pinpoint->recordQuery(['sql' => 'select 1', 'fingerprint' => 'x', 'time_ms' => 1, 'caller' => null]);

        $this->get('/pinpoint-test');

        $this->assertSame([], $pinpoint->queries());
        $this->assertDatabaseCount('pinpoint_requests', 0);
    }

    protected function defineRoutes($router)
    {
        $router->get('/pinpoint-test', fn () => response(DB::select('select 1')));
        $router->get('/pinpoint-lazy', fn () => response(User::all()->each->posts));
        $router->get('/pinpoint-raw-repeat', function () {
            for ($i = 0; $i < 4; $i++) {
                DB::select('select * from users where id = ?', [$i]);
            }

            return response('ok');
        });
    }
}

class User extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function posts()
    {
        return $this->hasMany(Post::class);
    }
}

class Post extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}