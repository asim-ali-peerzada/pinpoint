<?php

namespace AsimAli\Pinpoint\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void observeLazyLoad(string $model, string $relation)
 *
 * @see \AsimAli\Pinpoint\Pinpoint
 */
class Pinpoint extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \AsimAli\Pinpoint\Pinpoint::class;
    }
}
