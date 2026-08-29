<?php

namespace AsimAli\Pinpoint\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class LocalOnly
{
    public function handle(Request $request, Closure $next): Response|SymfonyResponse
    {
        if (! config('pinpoint.enabled') || ! (app()->isLocal() || app()->hasDebugModeEnabled())) {
            abort(404);
        }

        return $next($request);
    }
}
