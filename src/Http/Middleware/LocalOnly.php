<?php

namespace AsimAli\Pinpoint\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class LocalOnly
{
    /**
     * Restricts access to local, development, or debug-enabled environments.
     */
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        if (! config('pinpoint.enabled') || ! (app()->environment('local', 'development', 'dev') || app()->hasDebugModeEnabled())) {
            abort(404);
        }

        return $next($request);
    }
}
