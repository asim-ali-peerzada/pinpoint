<?php

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

use function Termwind\renderUsing;

/**
 * Run an artisan command, capturing Termwind-rendered output (Termwind
 * renders through its global renderer, not the buffer passed to
 * Artisan::call). Returns the output with ANSI escapes stripped.
 */
function runArtisanCaptured(string $command, array $parameters = []): string
{
    $buffer = new BufferedOutput;

    renderUsing($buffer);
    Artisan::call($command, $parameters, $buffer);
    renderUsing(null);

    return preg_replace('/\e\[[0-9;]*m/', '', $buffer->fetch());
}
