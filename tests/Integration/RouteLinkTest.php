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

test('link tokens do not distort table column widths', function () {
    Route::get('/pinpoint-route-source', [RouteSource::class, 'handle'])->name('api.route-source');

    $renderer = app(CliRenderer::class);
    $hyperlinks = new ReflectionProperty(CliRenderer::class, 'hyperlinks');
    $buffer = new BufferedOutput;
    $buffer->setDecorated(true);

    renderUsing($buffer);
    $renderer->reportTable('Test', [
        ['route' => 'api.route-source', 'p95' => 1, 'avg' => 1, 'samples' => 1, 'tier' => 'good', 'n1' => 'No'],
        ['route' => 'api.other', 'p95' => 2, 'avg' => 2, 'samples' => 1, 'tier' => 'good', 'n1' => 'No'],
    ]);
    $tokens = $hyperlinks->getValue($renderer);
    renderUsing(null);

    // Every stored token is EXACTLY as wide as its visible label — Termwind
    // computes column geometry from the token, so an oversized token breaks
    // the table layout (borders drawn over text).
    foreach ($tokens as $token => $tag) {
        preg_match('/<href=[^>]+>(.*?)<\/>/', $tag, $m);
        expect(mb_strlen($token))->toBe(mb_strlen($m[1]));
    }

    // Column geometry in the rendered output: the route cell must be followed
    // by the p95 column at a POSITION consistent with the 15-char route label.
    $output = $buffer->fetch();
    $plain = preg_replace('/\e\[[0-9;]*m|\e\]8;;[^\e]*\e\\\\/', '', $output);
    preg_match('/\|\s*api\.route-source\s+\|\s*1\s+\|/', $plain, $m);

    expect($m[0] ?? '')->not->toBe('')
        ->and($output)->not->toContain('__PP_L_');
});
