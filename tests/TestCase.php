<?php

namespace AsimAli\Pinpoint\Tests;

use AsimAli\Pinpoint\PinpointServiceProvider;
use AsimAli\Pinpoint\Tests\Fixtures\User;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [PinpointServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('pinpoint.enabled', true);
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
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
