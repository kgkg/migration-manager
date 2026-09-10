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
