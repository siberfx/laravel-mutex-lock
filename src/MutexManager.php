<?php

declare(strict_types=1);

namespace Siberfx\MutexLock;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Malkusch\Lock\Mutex\Mutex;
use Malkusch\Lock\Mutex\MySQLMutex;
use Malkusch\Lock\Mutex\PostgreSQLMutex;
use Malkusch\Lock\Util\DoubleCheckedLocking;
use Siberfx\MutexLock\Exceptions\UnsupportedDriverException;
use Siberfx\MutexLock\Mutex\SqliteMutex;

class MutexManager
{
    /** Longest name MySQL's GET_LOCK() accepts once php-lock adds its "php-malkusch-lock:" prefix. */
    private const int MYSQL_MAX_NAME_LENGTH = 46;

    /**
     * Smallest acquire timeout handed to spinlock drivers. php-lock's Loop never
     * runs when the timeout is exactly 0, so "try once" is expressed as a tiny window.
     */
    private const float MIN_SPIN_TIMEOUT = 0.01;

    /** @var array<string, true> SQLite connections whose lock table has been ensured */
    private array $preparedTables = [];

    /**
     * @param array{connection?: ?string, timeout?: int|float|string|null, prefix?: ?string, sqlite?: array{table?: string, expire?: int|float|string|null, auto_create_table?: bool}} $config
     */
    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly array $config = [],
    ) {}

    /**
     * Create a mutex for the given name.
     *
     * @param float|int|null $timeout    Seconds to wait for the lock; INF waits indefinitely, null uses the configured default
     * @param string|null    $connection Database connection name; null uses the configured default
     */
    public function make(string $name, float|int|null $timeout = null, ?string $connection = null): Mutex
    {
        if ($name === '') {
            throw new \InvalidArgumentException('The mutex name must not be empty.');
        }

        $connection = $this->db->connection($connection ?? $this->config['connection'] ?? null);
        $name = ($this->config['prefix'] ?? '') . $name;
        $timeout = $this->resolveTimeout($timeout);

        return match ($driver = $connection->getDriverName()) {
            'mysql', 'mariadb' => $this->makeMySql($connection, $name, $timeout),
            'pgsql' => $this->makePostgres($connection, $name, $timeout),
            'sqlite' => $this->makeSqlite($connection, $name, $timeout),
            default => throw UnsupportedDriverException::forDriver($driver),
        };
    }

    /**
     * Execute the callback while holding the named lock.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function synchronized(string $name, callable $callback, float|int|null $timeout = null, ?string $connection = null): mixed
    {
        return $this->make($name, $timeout, $connection)->synchronized($callback);
    }

    /**
     * Start a double-checked locking block; call ->then() on the result.
     *
     * @param callable(): bool $check
     */
    public function check(string $name, callable $check, float|int|null $timeout = null, ?string $connection = null): DoubleCheckedLocking
    {
        return $this->make($name, $timeout, $connection)->check($check);
    }

    private function makeMySql(ConnectionInterface $connection, string $name, float $timeout): MySQLMutex
    {
        if (\strlen($name) > self::MYSQL_MAX_NAME_LENGTH) {
            $name = 'sha1:' . sha1($name);
        }

        // GET_LOCK() treats a negative timeout as "wait forever".
        return new MySQLMutex($connection->getPdo(), $name, is_infinite($timeout) ? -1 : $timeout);
    }

    private function makePostgres(ConnectionInterface $connection, string $name, float $timeout): PostgreSQLMutex
    {
        return new PostgreSQLMutex($connection->getPdo(), $name, $this->spinTimeout($timeout));
    }

    private function makeSqlite(ConnectionInterface $connection, string $name, float $timeout): SqliteMutex
    {
        $options = $this->config['sqlite'] ?? [];
        $table = $options['table'] ?? SqliteMutex::DEFAULT_TABLE;
        $pdo = $connection->getPdo();

        $tableKey = $connection->getName() . '|' . $table;
        if (($options['auto_create_table'] ?? true) && ! isset($this->preparedTables[$tableKey])) {
            SqliteMutex::createTable($pdo, $table);
            $this->preparedTables[$tableKey] = true;
        }

        $expire = $options['expire'] ?? null;

        return new SqliteMutex(
            $pdo,
            $name,
            $this->spinTimeout($timeout),
            $expire === null || $expire === '' ? \INF : (float) $expire,
            $table,
        );
    }

    /**
     * @return float Seconds, INF when waiting indefinitely
     */
    private function resolveTimeout(float|int|null $timeout): float
    {
        if ($timeout === null) {
            $configured = $this->config['timeout'] ?? null;

            // A null/empty configured value means "wait indefinitely".
            $timeout = $configured === null || $configured === '' ? \INF : (float) $configured;
        }

        $timeout = (float) $timeout;

        if ($timeout < 0 || is_nan($timeout)) {
            throw new \InvalidArgumentException('The mutex timeout must be greater than or equal to 0.');
        }

        return $timeout;
    }

    private function spinTimeout(float $timeout): float
    {
        return max($timeout, self::MIN_SPIN_TIMEOUT);
    }
}
