<?php

declare(strict_types=1);

namespace Siberfx\MutexLock\Mutex;

use Malkusch\Lock\Exception\LockAcquireException;
use Malkusch\Lock\Mutex\AbstractSpinlockWithTokenMutex;
use Malkusch\Lock\Util\LockUtil;

/**
 * Table-backed spinlock for SQLite.
 *
 * SQLite has no advisory locks, so each lock is a row in a dedicated table.
 * A lock is taken with a single atomic UPSERT that only succeeds when the row
 * does not exist or has expired, and is released by deleting the row with the
 * token that acquired it. Rows left behind by crashed processes are reclaimed
 * once they expire.
 */
class SqliteMutex extends AbstractSpinlockWithTokenMutex
{
    public const string DEFAULT_TABLE = 'mutex_locks';

    /**
     * @param float $acquireTimeout In seconds
     * @param float $expireTimeout  In seconds, INF never expires
     */
    public function __construct(
        private readonly \PDO $pdo,
        string $name,
        float $acquireTimeout = 3,
        float $expireTimeout = \INF,
        private readonly string $table = self::DEFAULT_TABLE,
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new \InvalidArgumentException("Invalid lock table name [{$table}]");
        }

        parent::__construct($name, $acquireTimeout, $expireTimeout);
    }

    public static function createTable(\PDO $pdo, string $table = self::DEFAULT_TABLE): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
            throw new \InvalidArgumentException("Invalid lock table name [{$table}]");
        }

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS "{$table}" (
                "name" VARCHAR(255) NOT NULL PRIMARY KEY,
                "token" VARCHAR(64) NOT NULL,
                "expires_at" REAL NULL
            )
            SQL);
    }

    #[\Override]
    protected function acquireWithToken(string $key, float $expireTimeout)
    {
        $token = LockUtil::getInstance()->makeRandomToken();
        $now = microtime(true);
        $expiresAt = is_finite($expireTimeout) ? $now + $expireTimeout : null;

        try {
            $statement = $this->pdo->prepare(<<<SQL
                INSERT INTO "{$this->table}" ("name", "token", "expires_at") VALUES (?, ?, ?)
                ON CONFLICT ("name") DO UPDATE
                    SET "token" = excluded."token", "expires_at" = excluded."expires_at"
                    WHERE "{$this->table}"."expires_at" IS NOT NULL AND "{$this->table}"."expires_at" <= ?
                SQL);
            $statement->execute([$key, $token, $expiresAt, $now]);
        } catch (\PDOException $e) {
            if (self::isBusy($e)) {
                // Another writer holds the database; spin and try again.
                return false;
            }

            throw new LockAcquireException('Failed to acquire the SQLite lock: ' . $e->getMessage(), 0, $e);
        }

        return $statement->rowCount() === 1 ? $token : false;
    }

    #[\Override]
    protected function releaseWithToken(string $key, string $token): bool
    {
        $statement = $this->pdo->prepare("DELETE FROM \"{$this->table}\" WHERE \"name\" = ? AND \"token\" = ?");
        $statement->execute([$key, $token]);

        return $statement->rowCount() === 1;
    }

    private static function isBusy(\PDOException $e): bool
    {
        // SQLITE_BUSY (5) / SQLITE_LOCKED (6)
        $code = $e->errorInfo[1] ?? null;

        return $code === 5 || $code === 6
            || str_contains($e->getMessage(), 'database is locked')
            || str_contains($e->getMessage(), 'database table is locked');
    }
}
