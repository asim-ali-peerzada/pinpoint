<?php

namespace AsimAli\Pinpoint\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class LocalOnly
{
    // abort() throws (never returns); $next() yields Symfony's Response —
    // the Illuminate subclass added nothing but a false choice.
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        if (! config('pinpoint.enabled') || ! (app()->isLocal() || app()->hasDebugModeEnabled())) {
            abort(404);
        }

        return $next($request);
    }
}
