# Laravel Mutex Lock

Database-backed mutexes for Laravel 12 and 13, built on [php-lock/lock](https://github.com/php-lock/lock).

Use it to make sure a block of code runs in only one process at a time, across web workers, queue workers and servers. All of them must share the same database.

| Engine            | Mechanism                                           | Lock released on crash      |
| ----------------- | --------------------------------------------------- | --------------------------- |
| MySQL / MariaDB   | `GET_LOCK()` / `RELEASE_LOCK()` (php-lock `MySQLMutex`) | Yes, when the session ends |
| PostgreSQL        | `pg_advisory_lock()` (php-lock `PostgreSQLMutex`)   | Yes, when the session ends |
| SQLite            | Rows in a lock table (`SqliteMutex`, this package)  | After `sqlite.expire` seconds |

## Requirements

- PHP 8.4 or 8.5
- Laravel 12.x or 13.x
- `pdo_mysql`, `pdo_pgsql` or `pdo_sqlite`

## Installation

```bash
composer require siberfx/laravel-mutex-lock
```

The service provider and the `Mutex` facade are auto-discovered. To publish the config:

```bash
php artisan vendor:publish --tag=mutex-lock-config
```

## Usage

```php
use Siberfx\MutexLock\Facades\Mutex;

// Wait up to the configured timeout, run the callback, then release the lock.
$balance = Mutex::synchronized("account:{$account->id}", function () use ($account, $amount) {
    $account->refresh();
    $account->decrement('balance', $amount);

    return $account->balance;
});
```

### Timeouts

```php
Mutex::synchronized('report', $callback);              // configured default (mutex-lock.timeout)
Mutex::synchronized('report', $callback, timeout: 30); // wait up to 30 seconds
Mutex::synchronized('report', $callback, timeout: 0);  // try once
Mutex::synchronized('report', $callback, timeout: INF); // wait forever
```

If the lock can't be acquired in time, a `Malkusch\Lock\Exception\LockAcquireTimeoutException` is thrown:

```php
use Malkusch\Lock\Exception\LockAcquireTimeoutException;

try {
    Mutex::synchronized('import', fn () => $this->import(), timeout: 0);
} catch (LockAcquireTimeoutException) {
    return back()->with('status', 'An import is already running.');
}
```

### Double-checked locking

The check runs once before acquiring the lock and again after. The callback runs only when both checks pass. `then()` returns `false` when a check fails.

```php
Mutex::check("order:{$order->id}", fn () => ! $order->fresh()->shipped)
    ->then(fn () => $order->ship());
```

### Using another connection

```php
Mutex::synchronized('sync', $callback, connection: 'pgsql');
```

### Getting the php-lock mutex

`Mutex::make()` returns the underlying `Malkusch\Lock\Mutex\Mutex`:

```php
$mutex = Mutex::make('nightly-export', timeout: 5);

$mutex->synchronized(fn () => $this->export());
```

You can also inject `Siberfx\MutexLock\MutexManager` instead of using the facade.

### Queue job middleware

```php
use Siberfx\MutexLock\Jobs\Middleware\WithMutex;

public function middleware(): array
{
    return [
        (new WithMutex("invoice:{$this->invoice->id}"))->releaseAfter(10),
    ];
}
```

The middleware tries the lock once by default (`timeout` = 0). If the lock is busy, the job is released back onto the queue after `releaseAfter` seconds (default 0). Call `->dontRelease()` to throw `LockAcquireTimeoutException` instead.

## Configuration

`config/mutex-lock.php`:

| Key                         | Env                              | Default       | Description |
| --------------------------- | -------------------------------- | ------------- | ----------- |
| `connection`                | `MUTEX_CONNECTION`               | `null`        | Connection to lock on (`null` = default connection) |
| `timeout`                   | `MUTEX_TIMEOUT`                  | `10`          | Seconds to wait for a lock (`0` = try once, `null` = forever) |
| `prefix`                    | `MUTEX_PREFIX`                   | `''`          | Prepended to every lock name |
| `sqlite.table`              | `MUTEX_SQLITE_TABLE`             | `mutex_locks` | Lock table name |
| `sqlite.expire`             | `MUTEX_SQLITE_EXPIRE`            | `300`         | Seconds after which a lock left by a crashed process can be reclaimed (`null` = never) |
| `sqlite.auto_create_table`  | `MUTEX_SQLITE_AUTO_CREATE_TABLE` | `true`        | Create the lock table on first use |

To manage the SQLite lock table with migrations instead, set `MUTEX_SQLITE_AUTO_CREATE_TABLE=false` and publish the migration:

```bash
php artisan vendor:publish --tag=mutex-lock-migrations
php artisan migrate
```

## Things to know

- **Re-entrancy.** MySQL/MariaDB and PostgreSQL locks are held by the database session. Nested `synchronized()` calls with the same name on the same connection don't block each other. SQLite locks are not re-entrant, so a nested call with the same name waits for itself and then times out.
- **Separate processes only.** Two processes contend for a lock only when they use different database sessions. Laravel gives each PHP process its own connection, so this is normally the case.
- **Reconnects.** If the connection drops while a MySQL or PostgreSQL lock is held, the server releases the lock. Keep critical sections short.
- **Transactions.** Take the lock *outside* `DB::transaction()`. On SQLite, the lock row written inside an uncommitted transaction is invisible to other connections.
- **SQLite expiry.** If the code inside the lock runs longer than `sqlite.expire`, another process may take the lock. When that happens, releasing the lock throws `Malkusch\Lock\Exception\ExecutionOutsideLockException`. Set `expire` well above your longest critical section.
- **Long names on MySQL.** `GET_LOCK()` names are limited to 64 characters, and php-lock uses 18 of them for its prefix. Names longer than 46 characters are hashed automatically.
- **Timeout of 0 on PostgreSQL/SQLite.** php-lock's spin loop never runs with a timeout of exactly 0, so the package uses a 10 ms window. This gives the same "try once" behaviour.

## Testing

```bash
composer test                                                                # SQLite
DB_CONNECTION=mysql   DB_DATABASE=testing DB_USERNAME=root     vendor/bin/phpunit
DB_CONNECTION=mariadb DB_DATABASE=testing DB_USERNAME=root     vendor/bin/phpunit
DB_CONNECTION=pgsql   DB_DATABASE=testing DB_USERNAME=postgres vendor/bin/phpunit
```

CI runs PHP 8.4/8.5 × Laravel 12/13 × SQLite/MySQL/MariaDB/PostgreSQL, with both lowest and latest dependencies.

## License

MIT
