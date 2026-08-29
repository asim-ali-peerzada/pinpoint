<?php

namespace AsimAli\Pinpoint;

class Caller
{
    /**
     * Capture the first stack frame inside the app's base path.
     *
     * debug_backtrace is the most expensive thing this package does:
     * DEBUG_BACKTRACE_IGNORE_ARGS avoids capturing full argument values at
     * every frame (the source of memory spikes), and the depth limit stops
     * it walking the entire call stack.
     */
    public static function capture(string $basePath): ?array
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);

        foreach ($frames as $frame) {
            $file = $frame['file'] ?? null;

            if ($file !== null && str_starts_with($file, $basePath)) {
                return [
                    'file' => $file,
                    'line' => $frame['line'] ?? 0,
                ];
            }
        }

        return null;
    }
}