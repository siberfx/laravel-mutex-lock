<?php

declare(strict_types=1);

namespace Siberfx\MutexLock\Facades;

use Illuminate\Support\Facades\Facade;
use Siberfx\MutexLock\MutexManager;

/**
 * @method static \Malkusch\Lock\Mutex\Mutex make(string $name, float|int|null $timeout = null, ?string $connection = null)
 * @method static mixed synchronized(string $name, callable $callback, float|int|null $timeout = null, ?string $connection = null)
 * @method static \Malkusch\Lock\Util\DoubleCheckedLocking check(string $name, callable $check, float|int|null $timeout = null, ?string $connection = null)
 *
 * @see MutexManager
 */
class Mutex extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return MutexManager::class;
    }
}
