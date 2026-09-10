# Configuration and PHP usage

Start with the [quick start](../README.md) for the shortest route to a working migration.

## Configuration options

The configuration is a PHP file returning an array. The default filename is
`migration.config.php`, located in the command's working directory.

| Key | Required / default | Meaning |
| --- | --- | --- |
| `migrations_path` | Required | Nonempty absolute path or path relative to the configuration directory. |
| `connection` | Required | Callable returning `ConnectionInterface`; called lazily for database operations. |
| `table_name` | `schema_migrations` | History table: at most 64 ASCII characters, starting with a letter or underscore, then letters, digits or underscores. No database qualifier. |
| `lock_timeout` | `0` | Nonnegative integer seconds to wait for the migration lock. Zero fails immediately on contention. |
| `bootstrap` | `null` | Optional readable PHP file, absolute or relative to the configuration directory. |

Unknown keys and invalid values are rejected. The process working directory is
not changed. Windows absolute drive paths and UNC paths are supported; stream
wrappers and drive-relative paths such as `C:config.php` are rejected.

The configuration file is evaluated when loaded. Keep connection creation inside
its callable so `create` stays offline. On first connection access, the optional
bootstrap runs with `require_once`, then the factory runs. The connection or its
initialization failure is retained by that Configuration object. Bootstrap does
not run for `create` or `--help`; `init` does not load an existing configuration.

For application setup, add `'bootstrap' => 'bootstrap.php'` to the array. Your
bootstrap can register application autoloading or load environment variables.
`getenv()` reads the process environment; the package does not parse `.env`.
See the [generated configuration example](../examples/migration.config.php).

### Connection parameters

`MysqliConnection::connect()` accepts only these keys:

| Key | Required / default |
| --- | --- |
| `database` | Required nonblank string; the database must already exist. |
| `username` | Required nonblank string. |
| `password` | Required string; an empty password is allowed. |
| `host` | `127.0.0.1` |
| `port` | Integer `3306`; accepted range 1–65535. |
| `charset` | `utf8mb4` |

## CLI details

`--config file.php` and `--config=file.php` select a configuration, including for
`init`. The path is relative to the current working directory. Quote paths and
migration names containing spaces. `create` without a name prompts only when
STDIN is a terminal; otherwise it fails immediately.

`rollback` defaults to one step. Both `--steps=2` and `--steps 2` are accepted,
only once and only for rollback. Use positive decimal integers without leading
zeros, signs or fractions. Unknown commands, options and extra arguments fail.

## Use from PHP

Save this script in your application's root directory:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Kgkg\MigrationManager\Configuration;
use Kgkg\MigrationManager\MigrationFile;
use Kgkg\MigrationManager\MigrationManager;

$config = Configuration::load(__DIR__ . '/migration.config.php');
$manager = new MigrationManager(
    $config->getConnection(),
    $config->getMigrationsPath(),
    $config->getTableName(),
    $config->getLockTimeout()
);

$pending = $manager->getPending();
$applied = $manager->runPending(static function (MigrationFile $file, int $milliseconds): void {
    printf("Applied %s in %d ms\n", $file->getName(), $milliseconds);
});
```

`getPending()`, `runPending()` and `rollback()` return arrays of `MigrationFile`
objects in operation order. Files expose `getVersion()`, `getName()`,
`getClassName()` and `getPath()`. To undo changes separately, call
`$manager->rollback(1)`; it accepts the same optional callback as `runPending()`.

Callbacks run after successful SQL and history persistence, while the lock is
held. Their exceptions stop further work but do not undo the completed change.
Reported milliseconds cover loading and executing the migration, excluding
history writes and callback execution.

### Reuse an existing MySQLi connection

If your application already has a connected `$mysqli` instance:

```php
use Kgkg\MigrationManager\Connection\MysqliConnection;
use Kgkg\MigrationManager\MigrationManager;

$connection = new MysqliConnection($mysqli);
$manager = new MigrationManager($connection, __DIR__ . '/db/migrations');
$manager->runPending();
```

The adapter leaves the borrowed session's charset unchanged and never closes it.
Your application owns that connection. Connections created by `connect()` are
owned by the adapter and close when the adapter is destroyed. Neither path
reconnects automatically. Keep one session for the entire operation.

## Migration helpers

Migrations extend `AbstractMigration` and implement `public up(): void` and
`public down(): void`. The final constructor receives `ConnectionInterface`;
no custom constructor or application database class is needed.

Inside a migration, use `$this->execute($sql)` for one or more SQL statements,
`$this->hasColumn('notes', 'body')` for a column check, or
`$this->getDatabase()` for these connection methods:

| Method | Result |
| --- | --- |
| `execute(string $sql): void` | Executes all statements and consumes their results. |
| `executePrepared(string $sql, array $parameters = []): void` | Executes one statement with positional `?` parameters. |
| `fetchValue(string $sql, array $parameters = [])` | First column of first row; `null` for no row or SQL NULL. |
| `fetchAll(string $sql, array $parameters = []): array` | Associative rows, or an empty array. |
| `hasColumn(string $tableName, string $columnName): bool` | Checks the selected database. |
| `getDatabaseName(): string` | Returns the selected database name. |

For example, inside `up()`:

```php
$this->getDatabase()->executePrepared(
    'INSERT INTO notes (body) VALUES (?)',
    ['First note']
);
```

Parameters are a zero-based list of null, bool, int, float or string values.
Placeholders bind values, not table or column names. Query methods expose PHP
values, not native driver results; cast counts explicitly when needed.

For a migration that cannot be reversed:

```php
public function down(): void
{
    throw new \Kgkg\MigrationManager\IrreversibleMigrationException('Deleted data cannot be restored.');
}
```

This stops rollback and preserves its history entry. An empty `down()` does not
mark a migration irreversible.

## Custom connection adapter

Implement all six methods of
[`ConnectionInterface`](../src/Connection/ConnectionInterface.php), then return
your instance from the configuration's `connection` callable, or pass it to
`new MigrationManager($connection, $directory)`. Register your class with your
application's Composer autoloader. No package source changes are needed.

The adapter must retain one MySQL session across lock, SQL and history operations,
consume all SQL results, report failures as `ConnectionException`, and return
plain PHP values. Follow the [connection contract](implementation-decisions.md).
The history and locking SQL is MySQL-specific; this extension point does not
provide support for other database engines or remove required PHP extensions.

## Local installation

Before the package is available from your configured Composer repository, add a
path repository to your application's `composer.json`. Adjust the path:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../migration-manager",
            "options": {
                "versions": { "kgkg/migration-manager": "dev-main" }
            }
        }
    ],
    "require": { "kgkg/migration-manager": "dev-main" }
}
```

Merge these entries with your existing settings, run `composer update
kgkg/migration-manager`, then continue with `vendor/bin/migration-manager init`.
This requires only the package checkout and your application.
