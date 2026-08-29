<?php

namespace AsimAli\Pinpoint;

use AsimAli\Pinpoint\Internal\Recorder;

/**
 * Pinpoint's public API surface. Everything else lives in Internal\.
 *
 * The one documented use for host apps: if you register your own
 * handleLazyLoadingViolationUsing() in a provider that boots AFTER this
 * package, call Pinpoint::observeLazyLoad() inside your own handler so
 * Pinpoint's N+1 signal keeps working.
 */
class Pinpoint
{
    public function __construct(protected Recorder $recorder) {}

    public function observeLazyLoad(string $model, string $relation): void
    {
        $this->recorder->recordLazyLoad($model, $relation);
    }
}
