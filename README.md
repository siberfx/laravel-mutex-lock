<div align="center">

# Laravel Mutex Lock

**Database-backed mutexes for Laravel, built on [php-lock/lock](https://github.com/php-lock/lock).**

Only one process runs a critical section at a time, across web requests, queue workers, the scheduler and servers. The lock lives in the database you already run, so you don't need Redis.

[![Tests](https://github.com/siberfx/laravel-mutex-lock/actions/workflows/tests.yml/badge.svg)](https://github.com/siberfx/laravel-mutex-lock/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/siberfx/laravel-mutex-lock.svg)](https://packagist.org/packages/siberfx/laravel-mutex-lock)
[![PHP](https://img.shields.io/badge/php-8.4%20%7C%208.5-777BB4.svg)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/laravel-12.x%20%7C%2013.x-FF2D20.svg)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

</div>

---

## Table of contents

- [Why](#why)
- [Supported engines](#supported-engines)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Usage](#usage)
  - [Running code under a lock](#running-code-under-a-lock)
  - [Timeouts](#timeouts)
  - [Handling a busy lock](#handling-a-busy-lock)
  - [Double-checked locking](#double-checked-locking)
  - [Choosing a connection](#choosing-a-connection)
  - [Dependency injection](#dependency-injection)
  - [Working with the raw php-lock mutex](#working-with-the-raw-php-lock-mutex)
  - [Queue job middleware](#queue-job-middleware)
- [Recipes](#recipes)
  - [Preventing double spending](#preventing-double-spending)
  - [Idempotent webhooks](#idempotent-webhooks)
  - [Inventory reservation](#inventory-reservation)
  - [Artisan commands that must not overlap](#artisan-commands-that-must-not-overlap)
  - [Cache stampede protection](#cache-stampede-protection)
  - [Lazy one-time initialisation](#lazy-one-time-initialisation)
  - [Per-tenant locks](#per-tenant-locks)
- [Configuration](#configuration)
- [Exceptions](#exceptions)
- [API reference](#api-reference)
- [How it works](#how-it-works)
- [Caveats](#caveats)
- [Mutex vs. `Cache::lock()`](#mutex-vs-cachelock)
- [Testing your code](#testing-your-code)
- [Running the package tests](#running-the-package-tests)
- [Changelog](#changelog)
- [Credits](#credits)
- [License](#license)

---

## Why

Race conditions don't show up in development. They show up in production, when two requests or two workers read the same row at the same moment. This package gives you a simple, explicit way to prevent that:

```php
Mutex::synchronized("wallet:{$wallet->id}", fn () => $wallet->withdraw($amount));
```

- **No extra infrastructure.** Locks use the MySQL, MariaDB, PostgreSQL or SQLite database your app already uses.
- **Crash-safe.** On MySQL, MariaDB and PostgreSQL, the server releases the lock when the process dies. On SQLite, locks expire.
- **Proven core.** The locking logic comes from [php-lock/lock](https://github.com/php-lock/lock). This package adds a Laravel-native API, config, a facade, queue middleware and a SQLite driver.
- **Modern stack.** PHP 8.4+, Laravel 12 and 13, strict types.

## Supported engines

| Engine          | Mechanism                                      | Driver                             | Released on crash               | Re-entrant* |
| --------------- | ---------------------------------------------- | ---------------------------------- | ------------------------------- | ----------- |
| MySQL 5.7+ / 8+ | `GET_LOCK()` / `RELEASE_LOCK()`                | `Malkusch\Lock\Mutex\MySQLMutex`   | ✅ When the session closes     | ✅          |
| MariaDB 10+     | `GET_LOCK()` / `RELEASE_LOCK()`                | `Malkusch\Lock\Mutex\MySQLMutex`   | ✅ When the session closes     | ✅          |
| PostgreSQL 12+  | `pg_advisory_lock()` / `pg_try_advisory_lock()` | `Malkusch\Lock\Mutex\PostgreSQLMutex` | ✅ When the session closes | ✅          |
| SQLite 3.24+    | Row in a lock table (atomic UPSERT)            | `Siberfx\MutexLock\Mutex\SqliteMutex` | ⏱ After `sqlite.expire` seconds | ❌       |

<sub>* Re-entrant means a nested lock with the same name on the **same connection** won't block itself.</sub>

## Requirements

- PHP **8.4** or **8.5**
- Laravel **12.x** or **13.x**
- One of `pdo_mysql`, `pdo_pgsql` or `pdo_sqlite`

## Installation

```bash
composer require siberfx/laravel-mutex-lock
```

The service provider and the `Mutex` facade are auto-discovered.

To publish the config file (optional):

```bash
php artisan vendor:publish --tag=mutex-lock-config
```

## Quick start

```php
use Siberfx\MutexLock\Facades\Mutex;

$result = Mutex::synchronized('reports:monthly', function () {
    // Only one process at a time runs this block.
    return Report::generateMonthly();
});
```

The lock is **always** released, whether the callback returns normally or throws.

---

## Usage

### Running code under a lock

`synchronized()` waits for the lock, runs your callback, releases the lock and returns the callback's result.

```php
use Siberfx\MutexLock\Facades\Mutex;

$invoice = Mutex::synchronized("invoice:{$order->id}", function () use ($order) {
    return $order->invoice ?? $order->invoice()->create([
        'number' => Invoice::nextNumber(),
        'total'  => $order->total,
    ]);
});
```

Exceptions thrown inside the callback are rethrown unchanged, after the lock is released:

```php
try {
    Mutex::synchronized('import', fn () => $importer->run());
} catch (ImportFailed $e) {
    // The lock has already been released at this point.
    report($e);
}
```

### Timeouts

The timeout is how long to **wait for** the lock, in seconds. It does not limit how long your callback may run.

```php
Mutex::synchronized('report', $callback);                // configured default (mutex-lock.timeout, 10s)
Mutex::synchronized('report', $callback, timeout: 30);   // wait up to 30 seconds
Mutex::synchronized('report', $callback, timeout: 2.5);  // fractional seconds are fine
Mutex::synchronized('report', $callback, timeout: 0);    // try once, don't wait
Mutex::synchronized('report', $callback, timeout: INF);  // wait as long as it takes
```

> [!NOTE]
> MySQL and MariaDB round `GET_LOCK()` timeouts up to whole seconds. PostgreSQL and SQLite support fractional timeouts.

### Handling a busy lock

If the lock can't be acquired in time, `Malkusch\Lock\Exception\LockAcquireTimeoutException` is thrown. Your callback does **not** run.

```php
use Malkusch\Lock\Exception\LockAcquireTimeoutException;
use Siberfx\MutexLock\Facades\Mutex;

public function store(Request $request)
{
    try {
        $import = Mutex::synchronized(
            "import:{$request->user()->id}",
            fn () => Import::start($request->file('csv')),
            timeout: 0,
        );
    } catch (LockAcquireTimeoutException) {
        return back()->withErrors('An import is already running. Please wait for it to finish.');
    }

    return to_route('imports.show', $import);
}
```

A small helper for "skip if busy" semantics:

```php
function whenFree(string $name, callable $callback, mixed $default = null): mixed
{
    try {
        return Mutex::synchronized($name, $callback, timeout: 0);
    } catch (LockAcquireTimeoutException) {
        return $default;
    }
}
```

### Double-checked locking

Double-checked locking avoids taking a lock when there's nothing to do. The check runs **before** the lock is acquired and **again after**. The callback runs only when both checks pass.

```php
$shipped = Mutex::check(
    "order:{$order->id}:ship",
    fn () => ! $order->fresh()->shipped_at,       // cheap check, no lock
)->then(
    fn () => $order->ship(),                      // runs under the lock
    fn () => false,                               // optional: runs if either check fails
);
```

Without the second callback, `then()` returns `false` when a check fails.

### Choosing a connection

By default, locks use `mutex-lock.connection`, or your default database connection if that's not set. You can override it per call:

```php
Mutex::synchronized('sync:crm', $callback, connection: 'pgsql');
Mutex::synchronized('legacy', $callback, timeout: 5, connection: 'legacy_mysql');
```

> [!IMPORTANT]
> Every process that competes for the same lock must use the **same database server**.

### Dependency injection

The facade proxies `Siberfx\MutexLock\MutexManager`, a container singleton. You can inject it instead:

```php
use Siberfx\MutexLock\MutexManager;

final class TransferMoney
{
    public function __construct(private readonly MutexManager $mutex) {}

    public function __invoke(Account $from, Account $to, int $cents): void
    {
        // Lock both accounts in a stable order to avoid deadlocks.
        [$first, $second] = collect([$from->id, $to->id])->sort()->values()->all();

        $this->mutex->synchronized("account:{$first}", fn () =>
            $this->mutex->synchronized("account:{$second}", fn () =>
                DB::transaction(function () use ($from, $to, $cents) {
                    $from->refresh()->decrement('balance', $cents);
                    $to->refresh()->increment('balance', $cents);
                })
            )
        );
    }
}
```

It's also available as `app('mutex-lock')`.

### Working with the raw php-lock mutex

`Mutex::make()` returns the underlying `Malkusch\Lock\Mutex\Mutex`. Use it to pass a mutex into code that is framework-agnostic:

```php
$mutex = Mutex::make('nightly-export', timeout: 5);

$exporter = new Exporter($mutex);   // accepts any php-lock Mutex

$mutex->synchronized(fn () => $exporter->run());
$mutex->check(fn () => $exporter->isPending())->then(fn () => $exporter->run());
```

### Queue job middleware

`WithMutex` wraps a job's `handle()` in a lock. By default it **tries once**. If the lock is busy, the job goes back on the queue.

```php
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Siberfx\MutexLock\Jobs\Middleware\WithMutex;

class RecalculateInvoice implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public function __construct(public Invoice $invoice) {}

    public function middleware(): array
    {
        return [
            (new WithMutex("invoice:{$this->invoice->id}"))->releaseAfter(15),
        ];
    }

    public function handle(): void
    {
        $this->invoice->recalculate();
    }
}
```

| Option                                       | Behaviour                                                        |
| -------------------------------------------- | ---------------------------------------------------------------- |
| `new WithMutex($name)`                       | Try once and release the job immediately if busy                |
| `new WithMutex($name, timeout: 5)`           | Wait up to 5 seconds for the lock before giving up               |
| `new WithMutex($name, connection: 'pgsql')`  | Lock on a specific connection                                    |
| `->releaseAfter(30)`                         | If busy, release the job back onto the queue with a 30 second delay |
| `->dontRelease()`                            | If busy, throw `LockAcquireTimeoutException` (the attempt fails) |

> [!TIP]
> Each release counts as an attempt, so set `$tries` (or `retryUntil()`) high enough for your contention level.

---

## Recipes

### Preventing double spending

```php
public function withdraw(Wallet $wallet, int $cents): Transaction
{
    return Mutex::synchronized("wallet:{$wallet->id}", function () use ($wallet, $cents) {
        $wallet->refresh(); // always re-read state *inside* the lock

        throw_if($wallet->balance < $cents, InsufficientFunds::class);

        return DB::transaction(function () use ($wallet, $cents) {
            $wallet->decrement('balance', $cents);

            return $wallet->transactions()->create(['amount' => -$cents]);
        });
    });
}
```

### Idempotent webhooks

Payment providers retry webhooks, sometimes several times in parallel. Lock on the event ID and check whether the event was already processed:

```php
public function __invoke(Request $request)
{
    $eventId = $request->input('id');

    Mutex::check(
        "webhook:{$eventId}",
        fn () => ! ProcessedWebhook::whereKey($eventId)->exists(),
    )->then(function () use ($eventId, $request) {
        DB::transaction(function () use ($eventId, $request) {
            HandleStripeEvent::dispatchSync($request->all());
            ProcessedWebhook::create(['id' => $eventId]);
        });
    });

    return response()->noContent();
}
```

### Inventory reservation

```php
public function reserve(Product $product, int $qty): Reservation
{
    return Mutex::synchronized("stock:{$product->sku}", function () use ($product, $qty) {
        $available = $product->stock - $product->reservations()->active()->sum('qty');

        if ($available < $qty) {
            throw new OutOfStock($product, $available);
        }

        return $product->reservations()->create([
            'qty'        => $qty,
            'expires_at' => now()->addMinutes(15),
        ]);
    }, timeout: 3);
}
```

### Artisan commands that must not overlap

This works across servers even without a shared cache:

```php
use Illuminate\Console\Command;
use Malkusch\Lock\Exception\LockAcquireTimeoutException;
use Siberfx\MutexLock\Facades\Mutex;

class SyncProducts extends Command
{
    protected $signature = 'products:sync';

    public function handle(): int
    {
        try {
            return Mutex::synchronized('command:products:sync', function () {
                $this->info('Syncing…');
                app(ProductSync::class)->run();

                return self::SUCCESS;
            }, timeout: 0);
        } catch (LockAcquireTimeoutException) {
            $this->warn('Another sync is already running. Skipping.');

            return self::SUCCESS;
        }
    }
}
```

```php
// routes/console.php
Schedule::command('products:sync')->everyFiveMinutes()->onOneServer();
```

### Cache stampede protection

When a hot cache key expires, only one process rebuilds it. The others wait, then read the fresh value:

```php
function rememberLocked(string $key, int $ttl, Closure $callback): mixed
{
    return Cache::get($key) ?? Mutex::check(
        "cache:{$key}",
        fn () => ! Cache::has($key),
    )->then(
        fn () => tap($callback(), fn ($value) => Cache::put($key, $value, $ttl)),
        fn () => Cache::get($key),
    );
}

$stats = rememberLocked('dashboard:stats', 300, fn () => Stats::expensiveQuery());
```

### Lazy one-time initialisation

```php
Mutex::check('tenant:bootstrap:' . $tenant->id, fn () => ! $tenant->bootstrapped_at)
    ->then(function () use ($tenant) {
        $tenant->createDefaultRoles();
        $tenant->seedSettings();
        $tenant->update(['bootstrapped_at' => now()]);
    });
```

### Per-tenant locks

Use the `prefix` option when several apps share one database server:

```dotenv
MUTEX_PREFIX="billing-api:"
```

Or build names that include the tenant:

```php
Mutex::synchronized("tenant:{$tenant->id}:invoice-numbering", fn () => $tenant->nextInvoiceNumber());
```

---

## Configuration

`config/mutex-lock.php`:

```php
return [
    'connection' => env('MUTEX_CONNECTION'),        // null = default DB connection
    'timeout'    => env('MUTEX_TIMEOUT', 10),       // seconds; 0 = try once; null = wait forever
    'prefix'     => env('MUTEX_PREFIX', ''),        // prepended to every lock name

    'sqlite' => [
        'table'             => env('MUTEX_SQLITE_TABLE', 'mutex_locks'),
        'expire'            => env('MUTEX_SQLITE_EXPIRE', 300),            // null = never expire
        'auto_create_table' => env('MUTEX_SQLITE_AUTO_CREATE_TABLE', true),
    ],
];
```

| Key                        | Env                              | Default       | Description |
| -------------------------- | -------------------------------- | ------------- | ----------- |
| `connection`               | `MUTEX_CONNECTION`               | `null`        | Connection to lock on (`null` = default connection) |
| `timeout`                  | `MUTEX_TIMEOUT`                  | `10`          | Seconds to wait for a lock (`0` = try once, `null` = forever) |
| `prefix`                   | `MUTEX_PREFIX`                   | `''`          | Prepended to every lock name |
| `sqlite.table`             | `MUTEX_SQLITE_TABLE`             | `mutex_locks` | Lock table name |
| `sqlite.expire`            | `MUTEX_SQLITE_EXPIRE`            | `300`         | Seconds after which a lock left by a crashed process can be reclaimed (`null` = never) |
| `sqlite.auto_create_table` | `MUTEX_SQLITE_AUTO_CREATE_TABLE` | `true`        | Create the lock table on first use |

### Managing the SQLite table with migrations

If you prefer migrations over auto-creation:

```dotenv
MUTEX_SQLITE_AUTO_CREATE_TABLE=false
```

```bash
php artisan vendor:publish --tag=mutex-lock-migrations
php artisan migrate
```

MySQL, MariaDB and PostgreSQL don't need a table.

---

## Exceptions

All exceptions come from php-lock and extend `Malkusch\Lock\Exception\MutexException` (a `RuntimeException`).

```
MutexException
├── LockAcquireException
│   └── LockAcquireTimeoutException     lock not acquired within the timeout
├── LockReleaseException                lock could not be released
│   └── ExecutionOutsideLockException   SQLite: callback outlived `sqlite.expire`
└── UnsupportedDriverException          (this package) connection driver not supported
```

When releasing the lock fails, `LockReleaseException` still gives you the outcome of your callback:

```php
use Malkusch\Lock\Exception\LockReleaseException;

try {
    Mutex::synchronized('job', fn () => $job->run());
} catch (LockReleaseException $e) {
    $result    = $e->getCodeResult();     // what the callback returned
    $exception = $e->getCodeException();  // what the callback threw, if anything
}
```

---

## API reference

```php
use Siberfx\MutexLock\Facades\Mutex;
```

| Method | Returns | Description |
| ------ | ------- | ----------- |
| `Mutex::synchronized(string $name, callable $callback, float\|int\|null $timeout = null, ?string $connection = null)` | `mixed` | Acquire, run `$callback`, release and return its result |
| `Mutex::check(string $name, callable $check, float\|int\|null $timeout = null, ?string $connection = null)` | `DoubleCheckedLocking` | Start a double-checked lock. Call `->then($success, ?$fail)` on the result |
| `Mutex::make(string $name, float\|int\|null $timeout = null, ?string $connection = null)` | `Malkusch\Lock\Mutex\Mutex` | Build the underlying php-lock mutex |

`$timeout`: `null` uses the configured default, `0` tries once, `INF` waits forever, and any other value is a number of seconds.

---

## How it works

**MySQL / MariaDB.** `SELECT GET_LOCK(name, timeout)` takes a named lock that belongs to the database session. `RELEASE_LOCK(name)` frees it. If the PHP process dies, the connection closes and the server frees the lock. Names are prefixed with `php-malkusch-lock:`. Names longer than 46 characters are hashed (`sha1:…`) to stay within MySQL's 64-character limit.

**PostgreSQL.** The lock name is hashed into two 32-bit keys for `pg_advisory_lock(k1, k2)`. If the timeout is infinite, it blocks on the server. Otherwise it polls `pg_try_advisory_lock` with exponential backoff until the timeout runs out. Like MySQL, the lock is released when the session ends.

**SQLite.** SQLite has no advisory locks, so each lock is a row `(name, token, expires_at)` in the `mutex_locks` table. A single atomic UPSERT acquires the lock. It succeeds only if the row doesn't exist or has expired. Release deletes the row with the acquiring token, so a process can never release someone else's lock. If the database is busy, the attempt counts as "not acquired yet" and is retried with backoff.

---

## Caveats

- **Take locks outside transactions.** Wrap `DB::transaction()` *inside* `synchronized()`, not the other way around. On SQLite, a lock row written inside an uncommitted transaction is invisible to other connections.
- **Contention requires separate sessions.** Laravel opens one connection per PHP process, so different requests and workers contend correctly. Within the *same* process and connection, MySQL/MariaDB and PostgreSQL locks are re-entrant: nesting the same name won't block. On SQLite, nesting the same name waits for itself and times out.
- **Reconnects drop server-side locks.** If the database connection is lost while a MySQL or PostgreSQL lock is held, the server releases the lock. Keep critical sections short. Don't call `DB::reconnect()` inside them.
- **SQLite expiry.** If a callback runs longer than `sqlite.expire`, another process may take the lock. You'll get an `ExecutionOutsideLockException` on release. Set `expire` comfortably above your longest critical section.
- **Timeout of 0 on PostgreSQL/SQLite.** php-lock's spin loop doesn't run with a timeout of exactly 0, so the package uses a 10 ms window. This gives the same "try once" behaviour.
- **Lock order.** When a callback needs several locks, always acquire them in the same order (for example, sorted by ID) to avoid deadlocks.

---

## Mutex vs. `Cache::lock()`

Laravel ships atomic locks through the cache. Both are good tools. Pick based on your infrastructure and failure model:

|                              | `Mutex` (this package)                    | `Cache::lock()`                           |
| ---------------------------- | ----------------------------------------- | ----------------------------------------- |
| Backend                      | Your SQL database                         | Redis, Memcached, DynamoDB, database, …   |
| Released if the process dies | Immediately (MySQL/PG), on expiry (SQLite) | On TTL expiry                            |
| TTL you must estimate        | No (MySQL/PG)                             | Yes                                       |
| Blocking wait                | Server-side (MySQL, PG with `INF`)        | Polling                                   |
| Double-checked locking       | Built in                                  | Manual                                    |

---

## Testing your code

The facade can be mocked like any Laravel facade. To just run the callback in tests:

```php
use Siberfx\MutexLock\Facades\Mutex;

Mutex::shouldReceive('synchronized')
    ->once()
    ->with('wallet:1', Mockery::type('callable'))
    ->andReturnUsing(fn ($name, $callback) => $callback());
```

To simulate a busy lock:

```php
use Malkusch\Lock\Exception\LockAcquireTimeoutException;

Mutex::shouldReceive('synchronized')
    ->andThrow(LockAcquireTimeoutException::create(0));

$this->post('/imports')->assertSessionHasErrors();
```

The real implementation also works in tests on an in-memory SQLite database. No mocking is needed when you only care about the callback's effects.

---

## Running the package tests

```bash
composer test                                                                  # SQLite (default)

DB_CONNECTION=mysql   DB_DATABASE=testing DB_USERNAME=root     vendor/bin/phpunit
DB_CONNECTION=mariadb DB_DATABASE=testing DB_USERNAME=root     vendor/bin/phpunit
DB_CONNECTION=pgsql   DB_DATABASE=testing DB_USERNAME=postgres vendor/bin/phpunit
```

`DB_HOST`, `DB_PORT` and `DB_PASSWORD` are also read. CI runs the full matrix: PHP 8.4/8.5 × Laravel 12/13 × SQLite/MySQL/MariaDB/PostgreSQL × lowest/latest dependencies.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Credits

- [Selim Görmüş](https://github.com/siberfx)
- [php-lock/lock](https://github.com/php-lock/lock) by Markus Malkusch, Willem Stuursma-Ruwen, Michael Voříšek and contributors

## License

The MIT License (MIT). See [LICENSE](LICENSE).
