<?php

namespace Kgkg\MigrationManager\Tests\Integration;

use Kgkg\MigrationManager\Connection\ConnectionException;
use Kgkg\MigrationManager\Connection\MysqliConnection;
use PHPUnit\Framework\TestCase;

final class MysqliConnectionTest extends TestCase
{
    private int $reportMode;

    protected function setUp(): void
    {
        $this->reportMode = (new \mysqli_driver())->report_mode;
    }

    protected function tearDown(): void
    {
        mysqli_report($this->reportMode);
    }

    private function parameters(): array
    {
        return [
            'host' => getenv('MIGRATION_MANAGER_TEST_HOST'),
            'port' => (int)getenv('MIGRATION_MANAGER_TEST_PORT'),
            'database' => getenv('MIGRATION_MANAGER_TEST_DATABASE'),
            'username' => getenv('MIGRATION_MANAGER_TEST_USERNAME'),
            'password' => getenv('MIGRATION_MANAGER_TEST_PASSWORD'),
        ];
    }

    /** @dataProvider reportingModes */
    public function testOperationsAndResults(int $mode): void
    {
        mysqli_report($mode);
        $db = MysqliConnection::connect($this->parameters());
        self::assertSame($this->parameters()['database'], $db->getDatabaseName());
        self::assertSame('utf8mb4', $db->fetchValue('SELECT @@character_set_connection'));
        $db->execute('  ');
        $table = 'adapter_' . bin2hex(random_bytes(6));
        try {
            $db->execute("CREATE TABLE `$table` (id INT PRIMARY KEY, value TEXT NULL); SELECT 'a;b'; SELECT 2");
            self::assertTrue($db->hasColumn($table, 'value'));
            self::assertFalse($db->hasColumn($table, 'missing'));
            self::assertFalse($db->hasColumn($table . "' OR 1=1 --", 'value'));
            $db->executePrepared("INSERT INTO `$table` VALUES (?, ?)", [1, "quote'; semicolon; 😀"]);
            $db->executePrepared("INSERT INTO `$table` VALUES (?, ?)", [2, null]);
            self::assertSame([
                ['id' => 1, 'value' => "quote'; semicolon; 😀"],
                ['id' => 2, 'value' => null],
            ], $db->fetchAll("SELECT * FROM `$table` ORDER BY id"));
            self::assertSame([], $db->fetchAll("SELECT * FROM `$table` WHERE id = ?", [9]));
            self::assertNull($db->fetchValue("SELECT value FROM `$table` WHERE id = 9"));
            self::assertNull($db->fetchValue("SELECT value FROM `$table` WHERE id = 2"));
            self::assertSame('0012', $db->fetchValue('SELECT ?', ['0012']));
            self::assertSame(1, (int)$db->fetchValue('SELECT 1 AS duplicate, 2 AS duplicate'));
            self::assertSame(1, (int)$db->fetchValue('SELECT ?', [true]));
            self::assertSame(0, (int)$db->fetchValue('SELECT ?', [false]));
            $original = true;
            $db->fetchValue('SELECT ?', [&$original]);
            self::assertTrue($original);
            self::assertSame(1.25, (float)$db->fetchValue('SELECT ?', [1.25]));
            $db->executePrepared('SELECT 1 UNION ALL SELECT 2');
            self::assertSame(42, (int)$db->fetchValue('SELECT 42'));
            self::assertSame($mode, (new \mysqli_driver())->report_mode);
        } finally {
            $db->execute("DROP TABLE IF EXISTS `$table`");
        }
    }

    /** @dataProvider sqlFailures */
    public function testErrorsAreNormalizedAndConnectionRemainsUsable(int $mode, string $method, string $sql): void
    {
        mysqli_report($mode);
        $db = MysqliConnection::connect($this->parameters());
        try {
            $db->$method($sql);
            self::fail('Invalid SQL must fail.');
        } catch (ConnectionException $error) {
            self::assertNotSame(0, $error->getCode());
            if (($mode & MYSQLI_REPORT_STRICT) !== 0) {
                self::assertInstanceOf(\mysqli_sql_exception::class, $error->getPrevious());
            } else {
                self::assertNull($error->getPrevious());
            }
        }
        self::assertSame(7, (int)$db->fetchValue('SELECT 7'));
        self::assertSame($mode, (new \mysqli_driver())->report_mode);
    }

    public function sqlFailures(): array
    {
        $cases = [];
        foreach ($this->reportingModes() as $name => [$mode]) {
            foreach ([
                ['execute', 'INVALID SQL; SELECT 1'],
                ['execute', 'SELECT 1; INVALID SQL; SELECT 2'],
                ['executePrepared', 'INVALID SQL'],
                ['fetchAll', 'SELECT * FROM missing_adapter_table_987654321'],
                ['executePrepared', 'SELECT 1; SELECT 2'],
            ] as $index => [$method, $sql]) {
                $cases[$name . '-' . $index] = [$mode, $method, $sql];
            }
        }
        return $cases;
    }

    public function reportingModes(): array
    {
        return ['off' => [MYSQLI_REPORT_OFF], 'errors' => [MYSQLI_REPORT_ERROR],
            'strict' => [MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT]];
    }

    public function testBorrowedConnectionRemainsOpen(): void
    {
        $p = $this->parameters();
        $native = new \mysqli($p['host'], $p['username'], $p['password'], $p['database'], $p['port']);
        try {
            $native->set_charset('latin1');
            $db = new MysqliConnection($native);
            self::assertSame('latin1', $db->fetchValue('SELECT @@character_set_connection'));
            self::assertSame($native->thread_id, (int)$db->fetchValue('SELECT CONNECTION_ID()'));
            unset($db);
            $result = $native->query('SELECT 19');
            self::assertSame('19', $result->fetch_row()[0]);
            $result->free();
        } finally {
            $native->close();
        }
    }

    public function testNoSelectedDatabaseFailsExplicitly(): void
    {
        $p = $this->parameters();
        $native = new \mysqli($p['host'], $p['username'], $p['password'], '', $p['port']);
        try {
            $this->expectException(ConnectionException::class);
            $this->expectExceptionMessage('No database is selected.');
            (new MysqliConnection($native))->getDatabaseName();
        } finally {
            $native->close();
        }
    }

    /** @dataProvider reportingModes */
    public function testConnectionFailures(int $mode): void
    {
        mysqli_report($mode);
        $p = $this->parameters();
        $p['password'] .= '_invalid_password';
        try {
            MysqliConnection::connect($p);
            self::fail('Invalid credentials must fail.');
        } catch (ConnectionException $error) {
            self::assertNotSame(0, $error->getCode());
            if (($mode & MYSQLI_REPORT_STRICT) !== 0) {
                self::assertInstanceOf(\mysqli_sql_exception::class, $error->getPrevious());
            }
        }
    }

    public function testParameterCountAndMissingResultFailures(): void
    {
        $db = MysqliConnection::connect($this->parameters());
        foreach ([['SELECT ?', []], ['SELECT 1', [1]], ['SET @adapter_test = 1', []]] as [$sql, $values]) {
            try {
                $db->fetchAll($sql, $values);
                self::fail('Invalid query contract must fail.');
            } catch (ConnectionException $error) {
                self::assertNotSame('', $error->getMessage());
            }
            self::assertSame(8, (int)$db->fetchValue('SELECT 8'));
        }
    }

    /** @dataProvider reportingModes */
    public function testPreparedExecutionFailureAndRecovery(int $mode): void
    {
        mysqli_report($mode);
        $db = MysqliConnection::connect($this->parameters());
        $table = 'adapter_' . bin2hex(random_bytes(6));
        try {
            $db->execute("CREATE TABLE `$table` (id INT PRIMARY KEY)");
            $db->executePrepared("INSERT INTO `$table` VALUES (?)", [1]);
            try {
                $db->executePrepared("INSERT INTO `$table` VALUES (?)", [1]);
                self::fail('Duplicate keys must fail at execution.');
            } catch (ConnectionException $error) {
                self::assertSame(1062, $error->getCode());
                if (($mode & MYSQLI_REPORT_STRICT) !== 0) {
                    self::assertInstanceOf(\mysqli_sql_exception::class, $error->getPrevious());
                }
            }
            self::assertSame(1, (int)$db->fetchValue("SELECT COUNT(*) FROM `$table`"));
        } finally {
            $db->execute("DROP TABLE IF EXISTS `$table`");
        }
    }

    /** @dataProvider reportingModes */
    public function testProcedureResultsAreDrainedIncludingLaterFailure(int $mode): void
    {
        mysqli_report($mode);
        $db = MysqliConnection::connect($this->parameters());
        $procedure = 'adapter_' . bin2hex(random_bytes(6));
        try {
            $db->execute("CREATE PROCEDURE `$procedure`(IN should_fail INT) BEGIN
                SELECT 'first;result' AS value; SELECT 2;
                IF should_fail = 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Test procedure failure'; END IF;
                END");
            self::assertSame([['value' => 'first;result']], $db->fetchAll("CALL `$procedure`(?)", [0]));
            $db->executePrepared("CALL `$procedure`(?)", [0]);
            foreach (['execute', 'executePrepared', 'fetchAll'] as $method) {
                try {
                    $db->$method("CALL `$procedure`(1)");
                    self::fail('Later procedure errors must fail.');
                } catch (ConnectionException $error) {
                    self::assertSame(1644, $error->getCode());
                    if (($mode & MYSQLI_REPORT_STRICT) !== 0) {
                        self::assertInstanceOf(\mysqli_sql_exception::class, $error->getPrevious());
                    }
                }
                self::assertSame(9, (int)$db->fetchValue('SELECT 9'));
            }
        } finally {
            $db->execute("DROP PROCEDURE IF EXISTS `$procedure`");
        }
    }

    public function testOwnedConnectionClosesWhenAdapterIsDisposed(): void
    {
        $observer = MysqliConnection::connect($this->parameters());
        $owned = MysqliConnection::connect($this->parameters());
        $id = (int)$owned->fetchValue('SELECT CONNECTION_ID()');
        self::assertSame(1, (int)$observer->fetchValue('SELECT COUNT(*) FROM information_schema.processlist WHERE ID = ?', [$id]));
        unset($owned);
        self::assertSame(0, (int)$observer->fetchValue('SELECT COUNT(*) FROM information_schema.processlist WHERE ID = ?', [$id]));
    }

    /** @dataProvider reportingModes */
    public function testInvalidCharsetIsReported(int $mode): void
    {
        mysqli_report($mode);
        $parameters = $this->parameters();
        $parameters['charset'] = 'invalid_charset_e02';
        $this->expectException(ConnectionException::class);
        MysqliConnection::connect($parameters);
    }
}
