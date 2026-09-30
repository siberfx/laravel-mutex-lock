<?php

declare(strict_types=1);

namespace Siberfx\MutexLock\Jobs\Middleware;

use Malkusch\Lock\Exception\LockAcquireTimeoutException;
use Siberfx\MutexLock\MutexManager;

/**
 * Queue job middleware that runs the job while holding a database mutex.
 *
 * public function middleware(): array
 * {
 *     return [(new WithMutex("invoice:{$this->invoice->id}"))->releaseAfter(10)];
 * }
 */
class WithMutex
{
    /** Seconds before a job that could not get the lock is retried; null lets the exception fail the attempt. */
    private ?int $releaseAfter = 0;

    public function __construct(
        private readonly string $name,
        private readonly float|int|null $timeout = 0,
        private readonly ?string $connection = null,
    ) {}

    public function releaseAfter(int $seconds): static
    {
        $this->releaseAfter = $seconds;

        return $this;
    }

    /** Throw LockAcquireTimeoutException instead of releasing the job back onto the queue. */
    public function dontRelease(): static
    {
        $this->releaseAfter = null;

        return $this;
    }

    public function handle(object $job, callable $next): mixed
    {
        try {
            return app(MutexManager::class)->synchronized(
                $this->name,
                static fn () => $next($job),
                $this->timeout,
                $this->connection,
            );
        } catch (LockAcquireTimeoutException $e) {
            if ($this->releaseAfter === null || ! method_exists($job, 'release')) {
                throw $e;
            }

            $job->release($this->releaseAfter);

            return null;
        }
    }
}
