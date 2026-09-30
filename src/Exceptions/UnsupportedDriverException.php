<?php

declare(strict_types=1);

namespace Siberfx\MutexLock\Exceptions;

use Malkusch\Lock\Exception\MutexException;

class UnsupportedDriverException extends MutexException
{
    public static function forDriver(string $driver): self
    {
        return new self("Database driver [{$driver}] is not supported. Supported drivers: mysql, mariadb, pgsql, sqlite.");
    }
}
