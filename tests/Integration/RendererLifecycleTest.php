<?php

use AsimAli\Pinpoint\Internal\CliRenderer;
use Symfony\Component\Console\Output\BufferedOutput;

use function Termwind\renderUsing;

test('hyperlink tokens reset between renders on a reused renderer instance', function () {
    $renderer = app(CliRenderer::class);
    $hyperlinks = new ReflectionProperty(CliRenderer::class, 'hyperlinks');

    $render = function (callable $fn) use ($renderer): string {
        $buffer = new BufferedOutput;

        renderUsing($buffer);
        $fn($renderer);
        renderUsing(null);

        return preg_replace('/\e\[[0-9;]*m/', '', $buffer->fetch());
    };

    $first = $render(fn ($r) => $r->suggestions([
        ['model' => 'App\Models\User', 'relations' => 'posts', 'caller_file' => 'app/Http/Controllers/FirstController.php', 'caller_line' => 10],
    ]));

    // Tokens must be cleared after each render unit — otherwise the map grows
    // unbounded across renders on long-lived instances (Octane, test suites).
    expect($hyperlinks->getValue($renderer))->toBe([])
        ->and($first)->toContain('app/Http/Controllers/FirstController.php:10')
        ->not->toContain('__PINPOINT_LINK_');

    $second = $render(fn ($r) => $r->suggestions([
        ['model' => 'App\Models\User', 'relations' => 'posts', 'caller_file' => 'app/Http/Controllers/SecondController.php', 'caller_line' => 20],
    ]));

    expect($hyperlinks->getValue($renderer))->toBe([])
        ->and($second)->toContain('app/Http/Controllers/SecondController.php:20')
        ->not->toContain('__PINPOINT_LINK_');
});

test('caller link with a null line renders a placeholder, not file:0', function () {
    $renderer = app(CliRenderer::class);

    $output = '';
    $buffer = new BufferedOutput;

    renderUsing($buffer);
    $renderer->suggestions([
        ['model' => 'App\Models\User', 'relations' => 'posts', 'caller_file' => 'app/NoLine.php', 'caller_line' => null],
    ]);
    renderUsing(null);

    $output = preg_replace('/\e\[[0-9;]*m/', '', $buffer->fetch());

    expect($output)
        ->toContain('App\Models\User')
        ->not->toContain('NoLine.php:0')
        ->not->toContain('__PINPOINT_LINK_');
});
