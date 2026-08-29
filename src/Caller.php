<?php

namespace AsimAli\Pinpoint;

class Caller
{
    /**
     * Capture the first stack frame inside the app's base path, excluding
     * vendor/ (so file:line points at app code, not package internals).
     *
     * debug_backtrace is the most expensive thing this package does:
     * DEBUG_BACKTRACE_IGNORE_ARGS avoids capturing full argument values at
     * every frame (the source of memory spikes). The depth limit is 50:
     * a real query's stack is ~33 frames deep at QueryExecuted time
     * (measured), and app code sits beyond 15.
     */
    public static function capture(string $basePath): ?array
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 50);

        $vendorPath = $basePath.'/vendor';

        foreach ($frames as $frame) {
            $file = $frame['file'] ?? null;

            if ($file !== null
                && str_starts_with($file, $basePath)
                && ! str_starts_with($file, $vendorPath)
            ) {
                return [
                    'file' => $file,
                    'line' => $frame['line'] ?? 0,
                ];
            }
        }

        return null;
    }
}
