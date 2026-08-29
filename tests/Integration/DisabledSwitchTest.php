<?php

use AsimAli\Pinpoint\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

class DisabledSwitchTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('pinpoint.enabled', false);
    }

    protected function defineDatabaseMigrations()
    {
        // No migrations needed: this test only checks Eloquent static state.
    }

    public function test_master_switch_off_leaves_eloquent_strict_mode_untouched(): void
    {
        expect(Model::preventsLazyLoading())->toBeFalse();
    }
}
