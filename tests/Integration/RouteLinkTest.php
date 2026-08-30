<?php

use AsimAli\Pinpoint\Internal\CliRenderer;
use AsimAli\Pinpoint\Tests\Fixtures\RouteSource;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Output\BufferedOutput;

use function Termwind\renderUsing;

test('route link resolves a controller action to its file and line', function () {
    Route::get('/pinpoint-route-source', [RouteSource::class, 'handle'])->name('api.route-source');

    $renderer = app(CliRenderer::class);
    $buffer = new BufferedOutput;
    $buffer->setDecorated(true);

    renderUsing($buffer);
    $renderer->reportTable('Test', [[
        'route' => 'api.route-source', 'p95' => 1, 'avg' => 1, 'samples' => 1,
        'tier' => 'good', 'n1' => 'No',
    ]]);
    renderUsing(null);

    $output = $buffer->fetch();

    expect($output)->toContain('vscode://file/')
        ->not->toContain('vscode://file//')
        ->toContain('RouteSource.php')
        ->toContain('api.route-source')
        ->not->toContain('__PINPOINT_LINK_');
});

test('route links use a custom editor scheme when configured', function () {
    config()->set('pinpoint.editor', 'devin');

    Route::get('/pinpoint-route-source', [RouteSource::class, 'handle'])->name('api.route-source');

    $renderer = app(CliRenderer::class);
    $buffer = new BufferedOutput;
    $buffer->setDecorated(true);

    renderUsing($buffer);
    $renderer->reportTable('Test', [[
        'route' => 'api.route-source', 'p95' => 1, 'avg' => 1, 'samples' => 1,
        'tier' => 'good', 'n1' => 'No',
    ]]);
    renderUsing(null);

    $output = $buffer->fetch();

    expect($output)->toContain('devin://file/')
        ->not->toContain('devin://file//')
        ->toContain('RouteSource.php');
});

test('route link resolves invokable controllers', function () {
    Route::get('/pinpoint-invokable', RouteSource::class)->name('api.invokable');

    $renderer = app(CliRenderer::class);
    $buffer = new BufferedOutput;
    $buffer->setDecorated(true);

    renderUsing($buffer);
    $renderer->reportTable('Test', [[
        'route' => 'api.invokable', 'p95' => 1, 'avg' => 1, 'samples' => 1,
        'tier' => 'good', 'n1' => 'No',
    ]]);
    renderUsing(null);

    $output = $buffer->fetch();

    expect($output)->toContain('vscode://file/')
        ->not->toContain('vscode://file//')
        ->toContain('RouteSource.php');
});

test('route link falls back to plain text for unknown routes', function () {
    $renderer = app(CliRenderer::class);
    $buffer = new BufferedOutput;
    $buffer->setDecorated(true);

    renderUsing($buffer);
    $link = $renderer->routeLink('api.no-such-route');
    renderUsing(null);

    expect($link)->toBe('api.no-such-route');
});
