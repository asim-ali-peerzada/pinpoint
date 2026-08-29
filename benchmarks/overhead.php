<?php

/**
 * Pinpoint overhead benchmark.
 *
 * Boots the Testbench skeleton app with an in-memory SQLite database, then
 * measures mean request duration for a query-heavy route with Pinpoint
 * disabled vs enabled (worst case: local environment with caller capture on).
 *
 * Each scenario boots a fresh app with the flag set BEFORE provider
 * registration, so the "disabled" baseline has no listeners at all.
 *
 * Usage: composer benchmark
 */

require __DIR__.'/../vendor/autoload.php';

use AsimAli\Pinpoint\PinpointServiceProvider;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Foundation\Application as Testbench;

const REQUESTS = 200;
const QUERIES_PER_REQUEST = 10;
const WARMUP = 20;
function makeApp(bool $enabled, bool $captureCaller = true): \Illuminate\Foundation\Application
{
    $app = Testbench::create(
        basePath: realpath(__DIR__.'/../vendor/orchestra/testbench-core/laravel')
    );

    $app->detectEnvironment(fn () => 'local');

    $app['config']->set('database.default', 'testing');
    $app['config']->set('database.connections.testing', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);

    $app['config']->set('pinpoint.enabled', $enabled);
    $app['config']->set('pinpoint.sample_rate', 1.0);
    $app['config']->set('pinpoint.capture_caller', $captureCaller);

    $app->register(PinpointServiceProvider::class);

    $app['router']->get('/bench', function () {
        for ($i = 0; $i < QUERIES_PER_REQUEST; $i++) {
            DB::select('select 1');
        }

        return response('ok');
    });

    $repository = new Illuminate\Database\Migrations\DatabaseMigrationRepository($app['db'], 'migrations');
    $repository->createRepository();

    $migrator = new Illuminate\Database\Migrations\Migrator($repository, $app['db'], $app->make('files'), $app->make('events'));
    $migrator->run([__DIR__.'/../database/migrations']);

    return $app;
}

function runScenario($app, string $label): float
{
    $kernel = $app->make(HttpKernel::class);

    for ($i = 0; $i < WARMUP; $i++) {
        $kernel->handle(Request::create('/bench'));
    }

    $times = [];

    for ($i = 0; $i < REQUESTS; $i++) {
        $start = hrtime(true);
        $kernel->handle(Request::create('/bench'));
        $times[] = (hrtime(true) - $start) / 1e6;
    }

    $mean = array_sum($times) / count($times);
    $p95 = percentile($times, 95);

    printf("%-24s mean %8.3f ms   p95 %8.3f ms\n", $label, $mean, $p95);

    return $mean;
}

function percentile(array $values, int $p): float
{
    sort($values);
    $rank = (int) ceil($p / 100 * count($values)) - 1;

    return $values[max(0, min($rank, count($values) - 1))];
}

echo sprintf("Requests: %d, queries per request: %d, warmup: %d\n", REQUESTS, QUERIES_PER_REQUEST, WARMUP);
echo str_repeat('-', 50)."\n";

$baseline = runScenario(makeApp(false), 'Pinpoint disabled');
$noCaller = runScenario(makeApp(true, false), 'Pinpoint enabled, no caller');
$enabled = runScenario(makeApp(true, true), 'Pinpoint enabled, caller on');

printf("\nMean overhead (no caller capture): %.3f ms (%.2f%%)\n", $noCaller - $baseline, ($noCaller - $baseline) / $baseline * 100);
printf("Mean overhead (local, caller capture): %.3f ms (%.2f%%)\n", $enabled - $baseline, ($enabled - $baseline) / $baseline * 100);