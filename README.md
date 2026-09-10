# migration-manager
A lightweight PHP database migration manager with pluggable database adapters and built-in MySQLi support.

Package: `kgkg/migration-manager`. Requires PHP 7.4 or 8.x, `ext-mysqli`,
`ext-mysqlnd` and `composer-runtime-api ^2.2`.
Classes use the `Kgkg\MigrationManager\` namespace, loaded from `src/` via PSR-4.
Development dependencies include PHPUnit 9.6, with test classes loaded from `tests/`.

Install dependencies with `composer install` and run tests with `composer test`.

Run the CLI from your application's root directory:

```sh
vendor/bin/migration-manager init
vendor/bin/migration-manager create AddUsersTable
vendor/bin/migration-manager show
vendor/bin/migration-manager run
vendor/bin/migration-manager rollback --steps=1
```

When working in this package directly, use `php bin/migration-manager` instead.
`init` creates `migration.config.php` and `db/migrations`, preserving existing
files. Edit the configuration's connection factory before using database commands.
`init` and `create` work without a database connection. Use `--help` for usage or
`--config path/to/custom.php` to select another configuration. Paths inside the
configuration are relative to its directory. `getenv()` reads the process
environment; applications must load `.env` themselves if needed.

`show` lists pending migrations without creating history or acquiring a lock.
`run` and `rollback` report each successful migration's time and a final count
with total elapsed time. Rollback defaults to one step and uses descending
version order. `--steps` accepts a positive integer and applies only to rollback.
Failures return code 1 and write to STDERR; successful commands return code 0.
Earlier successful migrations remain applied or rolled back if a later one fails.

The CLI uses the package's connection interface and the configured optional
bootstrap; it does not require application-specific database classes. Installation
and dependency updates do not run migrations. Legacy ComposerScripts and
src/migration.php entrypoints have been removed; use the package binary or
optional aliases in your application's Composer scripts.
