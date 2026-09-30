<?php

declare(strict_types=1);

namespace Siberfx\MutexLock\Tests;

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as Orchestra;
use Siberfx\MutexLock\MutexLockServiceProvider;

abstract class TestCase extends Orchestra
{
    /** Name of a second connection to the same database, i.e. a different session / process. */
    protected const string OTHER = 'other';

    protected static string $sqlitePath = '';

    #[\Override]
    protected function getPackageProviders($app): array
    {
        return [MutexLockServiceProvider::class];
    }

    #[\Override]
    protected function getPackageAliases($app): array
    {
        return ['Mutex' => \Siberfx\MutexLock\Facades\Mutex::class];
    }

    #[\Override]
    protected function defineEnvironment($app): void
    {
        $driver = env('DB_CONNECTION', 'sqlite');

        $connection = match ($driver) {
            'sqlite' => [
                'driver' => 'sqlite',
                'database' => self::sqlitePath(),
                'prefix' => '',
                'foreign_key_constraints' => true,
                'busy_timeout' => 1000,
            ],
            'mysql', 'mariadb' => [
                'driver' => $driver,
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '3306'),
                'database' => env('DB_DATABASE', 'testing'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '5432'),
                'database' => env('DB_DATABASE', 'testing'),
                'username' => env('DB_USERNAME', 'postgres'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
            default => throw new \RuntimeException("Unsupported test driver [{$driver}]"),
        };

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $connection);
        $app['config']->set('database.connections.' . self::OTHER, $connection);
        $app['config']->set('mutex-lock.timeout', 5);
    }

    protected function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    private static function sqlitePath(): string
    {
        if (self::$sqlitePath === '') {
            self::$sqlitePath = sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'mutex-lock-tests-' . getmypid() . '.sqlite';
            touch(self::$sqlitePath);
            register_shutdown_function(static fn () => @unlink(self::$sqlitePath));
        }

        return self::$sqlitePath;
    }
}
