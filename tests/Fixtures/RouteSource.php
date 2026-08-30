<?php

namespace AsimAli\Pinpoint\Tests\Fixtures;

class RouteSource
{
    public function handle(): void
    {
        // the reflection of this exact method row proves the jump target
    }

    public function __invoke(): void
    {
        // invokable controller target
    }
}
