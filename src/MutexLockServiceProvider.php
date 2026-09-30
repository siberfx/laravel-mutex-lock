<?php

declare(strict_types=1);

namespace Siberfx\MutexLock;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class MutexLockServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/mutex-lock.php', 'mutex-lock');

        $this->app->singleton(MutexManager::class, static fn (Application $app): MutexManager => new MutexManager(
            $app->make('db'),
            $app->make('config')->get('mutex-lock', []),
        ));

        $this->app->alias(MutexManager::class, 'mutex-lock');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/mutex-lock.php' => $this->app->configPath('mutex-lock.php'),
        ], 'mutex-lock-config');

        $this->publishesMigrations([
            __DIR__ . '/../database/migrations' => $this->app->databasePath('migrations'),
        ], 'mutex-lock-migrations');
    }
}
