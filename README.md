# migration-manager
A lightweight PHP database migration manager with pluggable database adapters and built-in MySQLi support.

Package: `kgkg/migration-manager`. Requires PHP 7.4 or 8.x and `ext-mysqli`.
Classes use the `Kgkg\MigrationManager\` namespace, loaded from `src/` via PSR-4.
Development dependencies include PHPUnit 9.6, with test classes loaded from `tests/`.

Install dependencies with `composer install` and run tests with `composer test`.

Run the CLI from your application's root directory:

```sh
vendor/bin/migration-manager create AddUsersTable
vendor/bin/migration-manager show
vendor/bin/migration-manager run
```

When working in this package directly, use `php bin/migration-manager` instead.
Migrations are stored in `db/migrations` relative to the current working directory.
The existing `show` and `run` commands require the application's `Database` class
to be available through its Composer autoloader; tests using that class also
require this integration.
