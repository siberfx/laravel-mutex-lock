<?php

declare(strict_types=1);

namespace Siberfx\MutexLock\Tests;

use Malkusch\Lock\Exception\LockAcquireTimeoutException;
use Siberfx\MutexLock\Facades\Mutex;
use Siberfx\MutexLock\Jobs\Middleware\WithMutex;

class WithMutexTest extends TestCase
{
    public function test_job_runs_inside_the_lock(): void
    {
        $job = $this->fakeJob();

        $result = (new WithMutex('job'))->handle($job, function (object $job) {
            $job->ran = true;

            return 'handled';
        });

        $this->assertSame('handled', $result);
        $this->assertTrue($job->ran);
    }

    public function test_job_is_released_when_the_lock_is_busy(): void
    {
        $job = $this->fakeJob();

        Mutex::synchronized('busy-job', static function () use ($job): void {
            (new WithMutex('busy-job', 0, self::OTHER))->releaseAfter(15)
                ->handle($job, static fn () => throw new \LogicException('Job must not run.'));
        });

        $this->assertSame(15, $job->releasedAfter);
    }

    public function test_dont_release_rethrows(): void
    {
        $this->expectException(LockAcquireTimeoutException::class);

        Mutex::synchronized('busy-job', static function (): void {
            (new WithMutex('busy-job', 0, self::OTHER))->dontRelease()
                ->handle(new \stdClass, static fn () => null);
        });
    }

    private function fakeJob(): object
    {
        return new class
        {
            public bool $ran = false;

            public ?int $releasedAfter = null;

            public function release(int $delay = 0): void
            {
                $this->releasedAfter = $delay;
            }
        };
    }
}
