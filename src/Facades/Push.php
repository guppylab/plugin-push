<?php

namespace Guppylab\Push\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool configure()
 * @method static array support()
 * @method static bool isSupported()
 * @method static bool unenroll()
 * @method static bool setBadge(int $count)
 * @method static bool clearBadge()
 *
 * @see \Guppylab\Push\Push
 */
class Push extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Guppylab\Push\Push::class;
    }
}
