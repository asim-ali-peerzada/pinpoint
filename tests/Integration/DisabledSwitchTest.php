<?php

use AsimAli\Pinpoint\Internal\Recorder;
use AsimAli\Pinpoint\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

class DisabledSwitchTest extends TestCase
{
    protected function setUp(): void
    {
        // Eloquent's strict-mode flag is a static that leaks across tests in
        // the same process: earlier tests (booted with Pinpoint enabled) flip
        // it on. Reset before the disabled app boots so this test asserts
        // what it intends, regardless of test order.
        Model::preventLazyLoading(false);

        parent::setUp();
    }

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

    public function test_master_switch_off_stops_recording(): void
    {
        $recorder = app(Recorder::class);

        expect($recorder->isRecording())->toBeFalse();
    }
}
