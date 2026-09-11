# Verification

Install development dependencies with Composer 2.2 or newer, then run
`composer test`. Integration tests require MySQL and explicit process variables:

```text
MIGRATION_MANAGER_TEST_ALLOW_DESTRUCTIVE=1
MIGRATION_MANAGER_TEST_HOST=127.0.0.1
MIGRATION_MANAGER_TEST_PORT=3306
MIGRATION_MANAGER_TEST_DATABASE=migration_manager_test_local
MIGRATION_MANAGER_TEST_USERNAME=migration_test
MIGRATION_MANAGER_TEST_PASSWORD=test-only
```

Use a dedicated database and an account with privileges only on that database.
Tests create and drop tables and triggers. With binary logging enabled, the test
server needs `log_bin_trust_function_creators=1` for trigger failure fixtures.
Never use application database credentials.

Run `composer test:integration`, followed by `php tests/consumer.php` using the
same environment. Set `COMPOSER_BINARY` to a Composer PHAR path if `composer`
is not available on PATH. On Windows, put the PHP version under test first on
PATH: Composer's generated `.bat` invokes `php` from PATH.

The consumer test copies the package's source, binary, example configuration and
metadata into an isolated path repository under `.test-runtime`. It excludes
checkout dependencies and database files, installs without development
dependencies using a Composer path mirror, verifies consumer autoloading, and
runs the generated proxy (the actual `.bat` on Windows). It checks init, create,
show without history creation, run, repeated run and rollback against database
effects and history. Install and update are repeated with a pending migration
to verify neither executes it. Temporary consumer files and command logs remain
under the ignored `.test-runtime` directory for diagnosis; test tables are
removed in a finally block.

## Recorded results

### TLS pool and history audit fixes — 2026-09-11

Windows/MySQL 8.4.9 checks passed on both PHP 7.4.33 and 8.1.31:
215 unit tests / 726 assertions and 76 integration tests / 568 assertions.
The installed Composer consumer cycle passed on both PHP versions. Linux/PHP 8.4
verification remains with the existing CI matrix and was not run locally.

Regression tests now cover:

- A real MySQLi persistent TLS connection seeded in the pool, followed by a factory
  request with another CA: the `p:` host is rejected before any pooled connection
  can be reused. Ordinary verified TLS remains covered by the existing tests.
- A different filename under an applied version: show, run and multi-step rollback
  fail before any new SQL/down method, preserve data/history and release locks.
- Seven incompatible history definitions: missing column, missing primary key,
  shortened name column, nullable timestamp, MyISAM engine, extra unique index
  and extra required column. Both the first run and a retry leave data untouched.
- The original valid four-column history remains readable without an upgrade.
  A trigger-induced history write error continues to exercise failures after SQL,
  since schema validation cannot prevent every runtime database error.

Post-change review covered persistent connection reuse, history identity checks,
schema metadata validation, rollback preflight, exception cleanup and installed
consumer behavior. No additional actionable vulnerability was confirmed in that
review. Remaining limits are explicit: migration files are trusted executable PHP;
same-name content changes are not checksummed; MySQL DDL and history writes are
not guaranteed atomic. Existing schemas are validated, never automatically altered.

One PHP 7.4 unit run intermittently printed a shebang in the entrypoint test;
the unchanged test passed on the subsequent full run. This environment has CLI
OPcache enabled; the underlying cause of that intermittent output was not proven.

### Second audit fixes — 2026-09-10

Verified on Windows with MySQL 8.4.9, PHP 7.4.33 and PHP 8.1.31:

- Unit suite: 213 tests / 724 assertions on each PHP version.
- Integration suite: 65 tests / 481 assertions on each PHP version.
- Installed consumer cycle: passed on both PHP versions.

New regression coverage:

- `MigrationManagerTest::test_unsafe_session_is_rejected_without_committing_caller_work`
  covers run and rollback with a pending write, an empty explicit transaction,
  and disabled autocommit. Caller changes remain pending until caller rollback;
  rejected calls do not create history, and the manager works after cleanup.
- `MysqliConnectionTest::testBorrowedTransactionAndCallerSavepointSurviveSessionGuard`
  uses native MySQLi transaction/savepoint methods with all three reporting modes.
- `MigrationManagerTest::test_case_aliases_share_a_lock_on_case_insensitive_servers`
  verifies contention across two sessions on Windows; on case-sensitive servers
  it verifies that distinct identifiers retain distinct lock names.
- `MigrationCreatorTest::test_creation_lock_prevents_competing_versions_and_allows_retry`
  interleaves two creators at timestamp selection and checks fail-fast contention,
  lock release, retry and distinct versions.
- Generator tests reject occupied built-in classes/interfaces and case-insensitive
  class collisions between migration filenames; a failed creation releases its lock.
- `MigrationRunTest::test_session_guard_runs_before_lock_or_history_access`
  verifies ordering independently of the driver.

Custom adapters must implement the two new `ConnectionInterface` methods;
see the migration notes in [usage.md](usage.md#custom-connection-adapter).
The updated code has not yet been verified by a remote Linux/PHP 8.4 CI run.

### Package audit fixes — 2026-09-10

Regression coverage for the four audit findings:

| Finding | Regression evidence |
| --- | --- |
| Brackets in directory paths and overwritten files | `MigrationCreatorTest::test_literal_directory_discovery_and_duplicate_protection` creates and discovers two migrations in `app[1]`, checks version advancement and preserves edited content on duplicate creation. `test_file_appearing_between_discovery_and_write_is_not_overwritten` simulates a competing write just before opening the target. |
| Missing verified TLS | `TlsConnectionTest` connects to a real MySQL server, checks its negotiated cipher and rejects an untrusted certificate. `MysqliConnectionTest` rejects invalid CA settings before connecting. |
| Empty generated rollback | `MigrationRollbackTest::test_generated_down_preserves_history_and_releases_lock` executes the generated default and verifies no history deletion and lock release. |
| Reserved generated class names | `MigrationCreatorTest::test_reserved_class_names_fail_without_creating_files` covers 23 keywords/types/import conflicts; existing normalization tests retain compatible names. |

Verified locally on Windows, MySQL 8.4.9:

- PHP 8.1.31: 206 unit tests / 709 assertions; 55 integration tests / 432 assertions.
- PHP 7.4.33: 205 unit tests / 708 assertions, followed by the added concurrent-write
  regression (1 test / 1 assertion); 55 integration tests / 432 assertions.
- Installed consumer cycle passed on both PHP versions, including Composer's
  Windows proxy, install/update, run, repeated run and rollback.

The updated Linux/PHP 8.4 CI configuration has not been executed locally.

For TLS integration tests, run
`php tests/Support/create-tls-fixtures.php .test-runtime/tls` (requires OpenSSL)
and configure only your dedicated test MySQL server with `server.pem` as both
its CA and server certificate, and `server-key.pem` as its key. Set
`MIGRATION_MANAGER_TEST_TLS_DIR` to the absolute fixture directory, alongside
the ordinary integration settings. Certificates expire after two days; regenerate
them before reuse. Never use these test certificates outside the test server.
Without this variable the TLS integration tests are explicitly skipped.
CI generates fresh certificates, reloads the service's TLS configuration and
sets the variable for every matrix job.

### Before the audit fixes

On 2026-09-10, Windows with MySQL 8.4.9 passed on PHP 7.4.33 and PHP 8.1.31:

| Check | Result on each PHP version |
| --- | --- |
| Unit suite | 174 tests, 650 assertions |
| Integration suite, including lock contention | 53 tests, 429 assertions |
| Composer consumer installation and CLI cycle | Passed, including Windows proxy |

The user confirmed that all GitHub Actions matrix jobs passed on Linux with
MySQL 8.4 and PHP 7.4, 8.1 and 8.4. Each job includes the unit suite, integration
suite and Composer consumer cycle. This records the reported CI results; CI logs
were not independently inspected during this update.

MariaDB has not been tested. The Composer PHP
constraint expresses install eligibility, not verification of every PHP 8 release.
