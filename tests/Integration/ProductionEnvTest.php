<?php

use AsimAli\Pinpoint\Tests\TestCase;

class ProductionEnvTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        $app['config']->set('pinpoint.enabled', true);
        $app['env'] = 'production';
        $app['config']->set('app.debug', false);
    }

    protected function defineDatabaseMigrations()
    {
        // No migrations: the middleware 404s before any DB access, and
        // Testbench's teardown migrate:rollback would prompt in production env.
    }

    public function test_api_is_blocked_in_production(): void
    {
        $this->getJson('/_pinpoint/api/v1/summaries')->assertNotFound();
    }
}
