<?php

namespace AsimAli\Pinpoint\Tests;

use AsimAli\Pinpoint\PinpointServiceProvider;
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
}