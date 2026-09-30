<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | The database connection used for locking. Null uses the application's
    | default connection. Supported drivers: mysql, mariadb, pgsql, sqlite.
    |
    */

    'connection' => env('MUTEX_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Acquire Timeout
    |--------------------------------------------------------------------------
    |
    | Seconds to wait for a lock before a LockAcquireTimeoutException is
    | thrown. Use 0 to try exactly once, or null to wait indefinitely.
    |
    */

    'timeout' => env('MUTEX_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Name Prefix
    |--------------------------------------------------------------------------
    |
    | Prepended to every lock name, useful when several applications share
    | the same database server.
    |
    */

    'prefix' => env('MUTEX_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | SQLite
    |--------------------------------------------------------------------------
    |
    | SQLite has no advisory locks, so locks are stored as rows in a table.
    | "expire" is the number of seconds after which a lock left behind by a
    | crashed process may be reclaimed (null = never). Code that runs longer
    | than this throws an ExecutionOutsideLockException on release.
    |
    */

    'sqlite' => [
        'table' => env('MUTEX_SQLITE_TABLE', 'mutex_locks'),
        'expire' => env('MUTEX_SQLITE_EXPIRE', 300),
        'auto_create_table' => env('MUTEX_SQLITE_AUTO_CREATE_TABLE', true),
    ],

];
