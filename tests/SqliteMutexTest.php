<?php

declare(strict_types=1);

namespace Siberfx\MutexLock\Tests;

use Illuminate\Support\Facades\DB;
use Malkusch\Lock\Exception\ExecutionOutsideLockException;
use Malkusch\Lock\Exception\LockAcquireTimeoutException;
use Siberfx\MutexLock\Facades\Mutex;
use Siberfx\MutexLock\Mutex\SqliteMutex;

class SqliteMutexTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        if ($this->driver() !== 'sqlite') {
            $this->markTestSkipped('SQLite only.');
        }

        SqliteMutex::createTable(DB::connection()->getPdo());
        DB::table('mutex_locks')->delete();
    }

    public function test_lock_row_exists_only_while_held(): void
    {
        Mutex::synchronized('row', function (): void {
            $this->assertSame(1, DB::table('mutex_locks')->count());
        });

        $this->assertSame(0, DB::table('mutex_locks')->count());
    }

    public function test_expired_lock_is_reclaimed(): void
    {
        DB::table('mutex_locks')->insert([
            'name' => 'php-malkusch-lock:stale',
            'token' => 'dead-process',
            'expires_at' => microtime(true) - 1,
        ]);

        $this->assertTrue(Mutex::synchronized('stale', static fn () => true, 0));
    }

    public function test_non_expiring_lock_is_not_reclaimed(): void
    {
        DB::table('mutex_locks')->insert([
            'name' => 'php-malkusch-lock:forever',
            'token' => 'other-process',
            'expires_at' => null,
        ]);

        $this->expectException(LockAcquireTimeoutException::class);

        Mutex::synchronized('forever', static fn () => true, 0);
    }

    public function test_running_past_the_expire_timeout_is_reported(): void
    {
        $mutex = new SqliteMutex(DB::connection()->getPdo(), 'slow', 1, 0.05);

        $this->expectException(ExecutionOutsideLockException::class);

        $mutex->synchronized(static fn () => usleep(100_000));
    }

    public function test_table_is_created_automatically(): void
    {
        DB::statement('DROP TABLE IF EXISTS mutex_locks_auto');
        $this->app['config']->set('mutex-lock.sqlite.table', 'mutex_locks_auto');
        $this->app->forgetInstance(\Siberfx\MutexLock\MutexManager::class);
        Mutex::clearResolvedInstances();

        $this->assertTrue(Mutex::synchronized('auto', static fn () => true));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('mutex_locks_auto'));
    }

    public function test_invalid_table_name_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SqliteMutex(DB::connection()->getPdo(), 'x', 1, \INF, 'bad"; DROP TABLE users; --');
    }
}
