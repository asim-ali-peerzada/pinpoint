<?php

namespace AsimAli\Pinpoint\Commands\Concerns;

use Illuminate\Support\Facades\File;

/**
 * @internal Shared --json / --json-to emission contract for Pinpoint
 * commands. Commands that expose machine-readable output must use this so
 * the CI-facing shape stays identical everywhere.
 */
trait EmitsJson
{
    /**
     * Emit the JSON payload. Two modes:
     *
     * - --json: pure JSON on stdout (the CI contract — scripts pipe it to jq
     *   or write it to a file themselves).
     * - --json-to: write to a file (auto-creating directories) and print a
     *   clear message with the resolved location, for humans.
     */
    protected function emitJson(array $payload): void
    {
        if ($path = $this->option('json-to')) {
            $path = $this->resolvePath($path);

            File::ensureDirectoryExists(dirname($path));

            file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $this->info(sprintf('JSON written to %s', $path));

            return;
        }

        // CI contract: plain JSON on stdout, no ANSI/HTML markup.
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function resolvePath(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }
}
