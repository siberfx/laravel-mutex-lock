<?php

declare(strict_types=1);

namespace Siberfx\MutexLock\Tests;

use Malkusch\Lock\Exception\LockAcquireTimeoutException;
use Malkusch\Lock\Mutex\MySQLMutex;
use Malkusch\Lock\Mutex\PostgreSQLMutex;
use Siberfx\MutexLock\Exceptions\UnsupportedDriverException;
use Siberfx\MutexLock\Facades\Mutex;
use Siberfx\MutexLock\Mutex\SqliteMutex;
use Siberfx\MutexLock\MutexManager;

class MutexTest extends TestCase
{
    public function test_it_resolves_the_driver_specific_mutex(): void
    {
        $expected = match ($this->driver()) {
            'mysql', 'mariadb' => MySQLMutex::class,
            'pgsql' => PostgreSQLMutex::class,
            'sqlite' => SqliteMutex::class,
        };

        $this->assertInstanceOf($expected, Mutex::make('resolve'));
    }

    public function test_synchronized_returns_the_callback_result(): void
    {
        $this->assertSame(42, Mutex::synchronized('result', static fn () => 42));
    }

    public function test_lock_is_held_during_execution_and_released_afterwards(): void
    {
        Mutex::synchronized('held', function (): void {
            $this->assertLockedFromOtherSession('held');
        });

        $this->assertSame('free', Mutex::synchronized('held', static fn () => 'free', 0, self::OTHER));
    }

    public function test_lock_is_released_when_the_callback_throws(): void
    {
        try {
            Mutex::synchronized('throws', static fn () => throw new \DomainException('boom'));
            $this->fail('Exception was not rethrown.');
        } catch (\DomainException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertTrue(Mutex::synchronized('throws', static fn () => true, 0, self::OTHER));
    }

    public function test_different_names_do_not_block_each_other(): void
    {
        $result = Mutex::synchronized('a', static fn () => Mutex::synchronized('b', static fn () => 'both', 0, self::OTHER));

        $this->assertSame('both', $result);
    }

    public function test_timeout_waits_before_giving_up(): void
    {
        Mutex::synchronized('waits', function (): void {
            $start = microtime(true);

            try {
                Mutex::synchronized('waits', static fn () => null, 1, self::OTHER);
                $this->fail('Lock was acquired while held elsewhere.');
            } catch (LockAcquireTimeoutException) {
                $this->assertGreaterThanOrEqual(0.9, microtime(true) - $start);
            }
        });
    }

    public function test_double_checked_locking(): void
    {
        $calls = 0;

        $this->assertSame('done', Mutex::check('dcl', static fn () => true)->then(function () use (&$calls) {
            $calls++;

            return 'done';
        }));

        $this->assertFalse(Mutex::check('dcl', static fn () => false)->then(function () use (&$calls): void {
            $calls++;
        }));

        $this->assertSame(1, $calls);
    }

    public function test_prefix_isolates_lock_names(): void
    {
        $prefixed = new MutexManager($this->app['db'], ['prefix' => 'app1:', 'timeout' => 0]);
        $plain = new MutexManager($this->app['db'], ['timeout' => 0]);

        $prefixed->synchronized('shared', function () use ($plain): void {
            $this->assertTrue($plain->synchronized('shared', static fn () => true, 0, self::OTHER));
        });
    }

    public function test_long_names_are_supported(): void
    {
        $name = str_repeat('very-long-lock-name-', 10);

        Mutex::synchronized($name, function () use ($name): void {
            $this->assertLockedFromOtherSession($name);
        });
    }

    public function test_it_is_resolvable_from_the_container(): void
    {
        $this->assertSame($this->app->make(MutexManager::class), $this->app->make('mutex-lock'));
    }

    public function test_empty_name_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Mutex::make('');
    }

    public function test_negative_timeout_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Mutex::make('negative', -1);
    }

    public function test_unsupported_driver_is_rejected(): void
    {
        $this->app['config']->set('database.connections.sqlsrv_test', ['driver' => 'sqlsrv', 'database' => 'x']);

        $this->expectException(UnsupportedDriverException::class);

        Mutex::make('unsupported', connection: 'sqlsrv_test');
    }

    private function assertLockedFromOtherSession(string $name): void
    {
        try {
            Mutex::synchronized($name, static fn () => null, 0, self::OTHER);
            $this->fail("Lock [{$name}] was acquired from another session while held.");
        } catch (LockAcquireTimeoutException) {
            $this->addToAssertionCount(1);
        }
    }
}
