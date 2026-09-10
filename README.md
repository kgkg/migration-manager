# migration-manager

Run versioned MySQL migrations from your terminal or PHP application.

## Quick start

You need PHP 7.4 or 8.x with `mysqli`, `mysqlnd` and `tokenizer`, Composer 2.2+, and an
existing MySQL database. Your database user must be able to execute your migration
SQL and create, read, insert into and delete from the migration history table.

### 1. Install and initialize

Run these commands from your application's root directory:

```sh
composer require kgkg/migration-manager
vendor/bin/migration-manager init
```

On Windows PowerShell, use `vendor\bin\migration-manager.bat` for the CLI commands.
The PHP executable must be on PATH.

`init` creates `migration.config.php` and `db/migrations`. It does not overwrite
existing files. Installation and dependency updates never run migrations.
If the package is not available in your Composer repository yet, use the
[local installation instructions](docs/usage.md#local-installation).

### 2. Set your database connection

In the generated `migration.config.php`, replace the connection parameters with
those for your database. For example:

```php
<?php

use Kgkg\MigrationManager\Connection\MysqliConnection;

return [
    'migrations_path' => 'db/migrations',
    'connection' => static function (): MysqliConnection {
        return MysqliConnection::connect([
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'my_app',
            'username' => 'my_app_user',
            'password' => 'replace-with-your-password',
            'charset' => 'utf8mb4',
        ]);
    },
];
```

Keep real credentials out of version control. The generated configuration also
supports process environment variables `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
`DB_USERNAME` and `DB_PASSWORD`. `.env` files are not loaded automatically.

For remote databases, configure `ssl_ca` with a trusted CA certificate file to
require encrypted transport and server identity verification; see [TLS configuration](docs/usage.md#tls-for-remote-databases).

### 3. Create your first migration

```sh
vendor/bin/migration-manager create CreateNotesTable
```

Open the generated `db/migrations/YYYYMMDDHHMMSS_create_notes_table.php` and
replace its contents with:

```php
<?php

use Kgkg\MigrationManager\AbstractMigration;

final class CreateNotesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('CREATE TABLE notes (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, body TEXT NOT NULL)');
    }

    public function down(): void
    {
        $this->execute('DROP TABLE notes');
    }
}
```

Keep the generated filename. `up()` applies the change; `down()` reverses it.
Creating a migration does not require a database connection.

### 4. Apply it

```sh
vendor/bin/migration-manager show
vendor/bin/migration-manager run
```

Your `notes` table now exists. Applied versions are recorded automatically in
`schema_migrations`, so running the command again skips them. Commit migration
files alongside your application code; create a new migration for each new change.

To undo this example, including its table and any notes stored in it:

```sh
vendor/bin/migration-manager rollback --steps=1
```

## Everyday commands

| Command | What it does |
| --- | --- |
| `init` | Creates configuration and migration directory without overwriting files. |
| `create AddColumn` | Generates an editable migration; works offline. |
| `show` | Lists pending migrations without creating history or taking a lock. |
| `run` | Applies pending migrations in ascending version order. |
| `rollback --steps=2` | Reverts up to two applied migrations in descending version order. |
| `--help` | Shows command usage without connecting to a database. |

Select another configuration with
`vendor/bin/migration-manager run --config=config/migrations.php`.
Relative paths inside that file are resolved from its directory.

Commands return `0` on success and `1` on failure; errors go to STDERR.
Run and rollback print migration timings and a summary. A failure stops the
operation; earlier successful changes remain completed.

## Before using rollback

Rollback follows version numbers, which may differ from execution order.
It needs the original migration file and a working `down()` method. Generated
migrations throw `IrreversibleMigrationException` until you implement `down()`.
Existing migration files are not changed. An explicitly empty
`down()` succeeds and removes history without undoing SQL. For a change that
cannot be reversed, throw `IrreversibleMigrationException` from `down()`.

MySQL schema changes are not guaranteed to be atomic. If SQL partially succeeds
or history cannot be saved, inspect the database and history before retrying.
Wrapping DDL in a transaction does not guarantee recovery. Concurrent run and
rollback commands share a database lock; the default is to fail immediately
when another process holds it.

Run migrations on a connection with autocommit enabled and no active transaction.
The manager rejects unsafe sessions before touching history, including borrowed
MySQLi connections. Concurrent `create` commands share a filesystem lock; retry
if another command is already creating a migration.

## More information

- [Configuration, PHP API and custom connections](docs/usage.md)
- [Connection and execution contracts](docs/implementation-decisions.md)
- [Testing and verified compatibility](docs/testing.md)

Locally verified: PHP 7.4.33 and 8.1.31 on Windows with MySQL 8.4.9.
CI verified: PHP 7.4, 8.1 and 8.4 on Linux with MySQL 8.4.
MariaDB compatibility is not claimed.

Licensed under the [MIT license](LICENSE).
