# Changelog

## [Unreleased]

### Added
- `MutexManager` / `Mutex` facade wrapping [php-lock/lock](https://github.com/php-lock/lock) for Laravel 12 and 13 (PHP 8.4+).
- MySQL / MariaDB support via `GET_LOCK()` (`MySQLMutex`), with automatic hashing of names longer than 46 characters.
- PostgreSQL support via advisory locks (`PostgreSQLMutex`).
- SQLite support via a new table-backed `SqliteMutex` with lock expiry for crashed processes.
- `WithMutex` queue job middleware.
- Publishable config (`mutex-lock-config`) and SQLite migration (`mutex-lock-migrations`).
