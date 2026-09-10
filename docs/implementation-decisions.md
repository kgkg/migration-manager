# Connection and execution contracts

## Compatibility

The package uses `Kgkg\MigrationManager\` with Composer PSR-4 autoloading and the
MIT license. PHP syntax targets 7.4. Runtime requirements include `mysqli`,
`mysqlnd` and Composer runtime API ^2.2. See [recorded test results](testing.md)
for verified versions; the Composer constraint does not prove compatibility
with every allowed version. SQL targets MySQL.

## Connection behavior

`MysqliConnection::connect()` owns its connection; the constructor taking a
native `mysqli` borrows it without changing its charset or closing it. Manager,
history and migrations use the same session. There is no reconnect mechanism.

`execute()` uses multiple-statement execution without splitting SQL on semicolons.
Whitespace-only input is a no-op. Results are consumed and freed, including after
an error in a later statement. Prepared operations accept one statement with
positional parameters, consume subsequent result sets, and propagate their errors.
Fetch methods expose only the first result set. Duplicate column names in
associative rows keep the last column; `fetchValue()` uses the first physical column.
Fetching from a statement without a result set is an error.

Parameters accept null, bool, int, float and string values in a zero-based list.
Booleans bind as 0/1, null as SQL NULL, and numeric strings remain strings. Named
parameters, unsupported values and mismatched counts are rejected. Numeric
result types may depend on the driver. No driver result objects escape the adapter.

`ConnectionException` extends `MigrationException`, which extends RuntimeException.
Driver errors are normalized regardless of global MySQLi reporting mode, with
the native exception retained as `previous` when available. The adapter does not
change `mysqli_report`. A broken connection is reported, not repaired.

## Versions and history

Files follow `YYYYMMDDHHMMSS_migration_name.php`; the generator normalizes names
and increments the timestamp on version collisions. Keep applied filenames and
versions unchanged. Existing history versions are skipped. The history table
contains `version`, `migration_name`, `executed_at` and `execution_time_ms`.

Discovery rejects duplicate versions. Loading rejects missing or invalid classes,
wrong inheritance and a class declared by another file. The same file can be
loaded by multiple managers in one process; each receives a fresh migration
instance. PHP cannot reload an edited class in that process. Migration and
configuration files are executable PHP and must come from trusted application code.

`getPending()` reads without creating history or taking a lock. `runPending()`
creates history as needed and applies pending versions in ascending order.
`rollback()` selects applied versions in descending order, which can differ from
actual execution order. A missing file, failed `down()` or explicit
`IrreversibleMigrationException` stops rollback and preserves the history entry.
Successful earlier steps remain completed.

## Locking and failures

Run and rollback acquire a MySQL advisory lock before reading applied versions or
writing history. Its name is `migration_manager_` followed by SHA-1 of the database
name, a NUL separator and history table name. Every operation uses that same
connection. Lock timeout is a nonnegative integer in seconds, defaulting to zero.
Different history tables have different locks; this does not coordinate unrelated
application SQL.

An acquired lock is released after success or failure. A release error propagates
only if there is no earlier error to preserve. A failed release may leave the
server lock held until the session ends; borrowed connections are not closed.
A manager rejects recursive execution on itself.

History is inserted only after successful `up()` and deleted only after successful
`down()`. Callbacks run after history persistence while holding the lock. A
callback failure does not reverse the completed history change.

No whole-migration atomicity is guaranteed. SQL may partially succeed, or SQL may
succeed while history persistence fails. Inspect actual schema/data and history
and repair them before retrying. MySQL DDL transactions cannot guarantee recovery.

## Verification

[Testing instructions](testing.md) cover unit tests, dedicated MySQL integration
tests and an isolated Composer consumer with the generated CLI proxy.
[Acceptance evidence](acceptance.md) maps requirements to implementation and tests.
