<?php

namespace Kgkg\MigrationManager\Tests\Integration;

use Kgkg\MigrationManager\Connection\ConnectionException;
use Kgkg\MigrationManager\Connection\MysqliConnection;
use Kgkg\MigrationManager\MigrationException;
use Kgkg\MigrationManager\MigrationHistory;
use Kgkg\MigrationManager\MigrationManager;
use Kgkg\MigrationManager\Tests\Unit\MigrationManagerTestCase;

final class MigrationManagerTest extends MigrationManagerTestCase
{
    /** @dataProvider identityOperations */
    public function test_changed_identity_fails_before_any_migration_work(string $operation): void
    {
        $this->db->execute("CREATE TABLE `{$this->effects}` (id INT)");
        $first = $this->migration('20260101000000', "INSERT INTO `{$this->effects}` VALUES (1)",
            '$this->execute("DELETE FROM ' . $this->effects . '");');
        $this->migration('20260101000001', "INSERT INTO `{$this->effects}` VALUES (2)",
            '$this->execute("DELETE FROM ' . $this->effects . '");');
        $manager = $this->manager();
        $manager->runPending();
        rename($first, $this->temporaryDirectory . '/20260101000000_other_branch.php');
        // A new pending migration must also remain untouched on run.
        $this->migration('20260101000002', "INSERT INTO `{$this->effects}` VALUES (3)");
        try {
            $operation === 'rollback' ? $manager->rollback(2) : $manager->{$operation}();
            $this->fail('Changed identity must be rejected.');
        } catch (MigrationException $error) {
            $this->assertStringContainsString('identity mismatch', $error->getMessage());
        }
        $this->assertSame(2, (int)$this->db->fetchValue("SELECT COUNT(*) FROM `{$this->effects}`"));
        $this->assertSame(2, (int)$this->db->fetchValue("SELECT COUNT(*) FROM `{$this->table}`"));
        $this->assertLockReleased();
    }

    public function identityOperations(): array
    {
        return [['runPending'], ['getPending'], ['rollback']];
    }

    /** @dataProvider incompatibleSchemas */
    public function test_incompatible_history_is_rejected_before_up_and_on_retry(string $alter): void
    {
        $this->db->execute("CREATE TABLE `{$this->effects}` (id INT)");
        (new MigrationHistory($this->db, $this->table))->ensureExists();
        $this->db->execute("ALTER TABLE `{$this->table}` " . $alter);
        $this->migration('20260101000000', "INSERT INTO `{$this->effects}` VALUES (1)");
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $this->manager()->runPending();
                $this->fail('Incompatible history must be rejected.');
            } catch (MigrationException $error) {
                $this->assertStringContainsString('Incompatible migration history', $error->getMessage());
            }
            $this->assertSame(0, (int)$this->db->fetchValue("SELECT COUNT(*) FROM `{$this->effects}`"));
            $this->assertSame(0, (int)$this->db->fetchValue("SELECT COUNT(*) FROM `{$this->table}`"));
            $this->assertLockReleased();
        }
    }

    public function incompatibleSchemas(): array
    {
        return [['DROP COLUMN migration_name'], ['DROP PRIMARY KEY'],
            ['MODIFY migration_name VARCHAR(10) NOT NULL'],
            ['MODIFY executed_at DATETIME NULL'], ['ENGINE=MyISAM'],
            ['ADD UNIQUE KEY extra_unique (migration_name)'], ['ADD extra_required INT NOT NULL']];
    }

    /** @dataProvider unsafeSessions */
    public function test_unsafe_session_is_rejected_without_committing_caller_work(string $state, string $operation): void
    {
        $this->db->execute("CREATE TABLE `{$this->effects}` (id INT) ENGINE=InnoDB");
        $this->db->execute($state === 'autocommit' ? 'SET autocommit=0' : 'START TRANSACTION');
        try {
            if ($state !== 'empty') {
                $this->db->execute("INSERT INTO `{$this->effects}` VALUES (1)");
            }
            try {
                $this->manager()->{$operation}();
                $this->fail('Unsafe session must be rejected.');
            } catch (ConnectionException $error) {
                $this->assertStringContainsString('transaction', $error->getMessage());
            }
            $this->assertFalse((new MigrationHistory($this->other, $this->table))->exists());
            $this->assertSame(0, (int)$this->other->fetchValue("SELECT COUNT(*) FROM `{$this->effects}`"));
            // Pending caller work is still present in its own session until rollback.
            $this->assertSame($state === 'empty' ? 0 : 1,
                (int)$this->db->fetchValue("SELECT COUNT(*) FROM `{$this->effects}`"));
        } finally {
            $this->db->execute('ROLLBACK; SET autocommit=1');
        }
        $this->assertSame(0, (int)$this->other->fetchValue("SELECT COUNT(*) FROM `{$this->effects}`"));
        $this->assertSame([], $this->manager()->{$operation}());
    }

    public function unsafeSessions(): array
    {
        return [['active', 'runPending'], ['active', 'rollback'], ['empty', 'runPending'],
            ['empty', 'rollback'], ['autocommit', 'runPending'], ['autocommit', 'rollback']];
    }

    public function test_case_aliases_share_a_lock_on_case_insensitive_servers(): void
    {
        $lower = $this->db->getMigrationLockName($this->table);
        $upper = $this->other->getMigrationLockName(strtoupper($this->table));
        if ((int)$this->db->fetchValue('SELECT @@lower_case_table_names') === 0) {
            $this->assertNotSame($lower, $upper);
            return;
        }
        $this->assertSame($lower, $upper);
        $this->assertSame(1, (int)$this->db->fetchValue('SELECT GET_LOCK(?, 0)', [$lower]));
        try {
            $this->expectException(MigrationException::class);
            $this->expectExceptionMessage('Another process');
            (new MigrationManager($this->other, $this->temporaryDirectory, strtoupper($this->table)))->runPending();
        } finally {
            $this->db->fetchValue('SELECT RELEASE_LOCK(?)', [$lower]);
        }
    }

    private MysqliConnection $db;
    private MysqliConnection $other;
    private string $table;
    private string $effects;

    protected function setUp(): void
    {
        parent::setUp();
        $parameters = [
            'host' => getenv('MIGRATION_MANAGER_TEST_HOST'),
            'port' => (int)getenv('MIGRATION_MANAGER_TEST_PORT'),
            'database' => getenv('MIGRATION_MANAGER_TEST_DATABASE'),
            'username' => getenv('MIGRATION_MANAGER_TEST_USERNAME'),
            'password' => getenv('MIGRATION_MANAGER_TEST_PASSWORD'),
        ];
        $this->db = MysqliConnection::connect($parameters);
        $this->other = MysqliConnection::connect($parameters);
        $this->table = 'history_' . bin2hex(random_bytes(6));
        $this->effects = 'effects_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->db)) {
                $this->db->execute("DROP TABLE IF EXISTS `{$this->table}`, `{$this->effects}`");
            }
        } finally {
            unset($this->db, $this->other);
            parent::tearDown();
        }
    }

    private function manager(?MysqliConnection $db = null): MigrationManager
    {
        return new MigrationManager($db ?? $this->db, $this->temporaryDirectory, $this->table);
    }

    private function migration(string $version, string $sql, string $down = ''): string
    {
        $name = 'integration_' . bin2hex(random_bytes(8));
        $class = str_replace('_', '', ucwords($name, '_'));
        return $this->writeMigrationFile($version . '_' . $name . '.php',
            '<?php final class ' . $class . ' extends \\Kgkg\\MigrationManager\\AbstractMigration {'
            . 'public function up(): void {$this->execute(' . var_export($sql, true) . ');}'
            . 'public function down(): void {' . $down . '}}');
    }

    private function lockName(): string
    {
        return 'migration_manager_' . sha1($this->db->getDatabaseName() . "\0" . $this->table);
    }

    private function assertLockReleased(): void
    {
        $this->assertSame(1, (int)$this->other->fetchValue('SELECT GET_LOCK(?, 0)', [$this->lockName()]));
        $this->assertSame(1, (int)$this->other->fetchValue('SELECT RELEASE_LOCK(?)', [$this->lockName()]));
    }

    public function test_pending_does_not_create_history_or_acquire_a_lock(): void
    {
        $this->migration('20260714120000', 'INVALID SQL');
        $this->other->fetchValue('SELECT GET_LOCK(?, 0)', [$this->lockName()]);
        try {
            $this->assertCount(1, $this->manager()->getPending());
            $this->assertFalse((new MigrationHistory($this->db, $this->table))->exists());
            (new MigrationHistory($this->db, $this->table))->ensureExists();
            $this->assertCount(1, $this->manager()->getPending());
            $this->assertSame(0, (int)$this->db->fetchValue("SELECT COUNT(*) FROM `{$this->table}`"));
        } finally {
            $this->other->fetchValue('SELECT RELEASE_LOCK(?)', [$this->lockName()]);
        }
    }

    public function test_existing_history_is_preserved_and_callbacks_follow_persistence(): void
    {
        // The original four-column history schema, without package-specific metadata.
        $this->db->execute("CREATE TABLE `{$this->table}` (version VARCHAR(14) PRIMARY KEY, "
            . 'migration_name VARCHAR(255) NOT NULL, executed_at DATETIME NOT NULL, '
            . 'execution_time_ms INT UNSIGNED NOT NULL) ENGINE=InnoDB');
        $this->db->executePrepared("INSERT INTO `{$this->table}` VALUES (?, ?, ?, ?)",
            ['20260714120000', 'legacy_migration', '2026-07-14 12:00:00', 12]);
        $this->writeMigrationFile('20260714120000_legacy_migration.php');
        $this->migration('20260714120001', "CREATE TABLE `{$this->effects}` (id INT PRIMARY KEY); "
            . "INSERT INTO `{$this->effects}` VALUES (1)");
        $this->migration('20260714120002', "INSERT INTO `{$this->effects}` VALUES (2)");
        $callbacks = [];
        $this->assertCount(2, $this->manager()->getPending());
        $executed = $this->manager()->runPending(function ($file, $ms) use (&$callbacks): void {
            $this->assertSame(1, (int)$this->other->fetchValue(
                "SELECT COUNT(*) FROM `{$this->table}` WHERE version = ?", [$file->getVersion()]));
            $this->assertSame((int)$this->db->fetchValue('SELECT CONNECTION_ID()'),
                (int)$this->other->fetchValue('SELECT IS_USED_LOCK(?)', [$this->lockName()]));
            $this->assertIsInt($ms);
            $this->assertGreaterThanOrEqual(0, $ms);
            $callbacks[] = $file->getVersion();
        });
        $this->assertCount(2, $executed);
        $this->assertSame(['20260714120001', '20260714120002'], $callbacks);
        $this->assertSame(2, (int)$this->db->fetchValue("SELECT COUNT(*) FROM `{$this->effects}`"));
        $this->assertSame('legacy_migration', $this->db->fetchValue(
            "SELECT migration_name FROM `{$this->table}` WHERE version = '20260714120000'"));
        $this->assertSame([], $this->manager()->runPending());
        $this->assertSame([], $this->manager()->getPending());
        $this->assertLockReleased();
    }

    /** @dataProvider failures */
    public function test_failed_sql_or_history_write_preserves_pending_and_releases_lock(string $failure): void
    {
        if ($failure === 'history') {
            (new MigrationHistory($this->db, $this->table))->ensureExists();
            $this->db->execute("CREATE TRIGGER `{$this->table}_reject` BEFORE INSERT ON `{$this->table}` "
                . "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'History write rejected'");
        }
        $sql = "CREATE TABLE `{$this->effects}` (id INT); INSERT INTO `{$this->effects}` VALUES (1)";
        if ($failure === 'first_sql') {
            $sql = 'INVALID SQL; ' . $sql;
        } elseif ($failure === 'later_sql') {
            $sql .= '; INVALID SQL';
        }
        $this->migration('20260714120000', $sql);
        $called = false;
        try {
            $this->manager()->runPending(function () use (&$called): void { $called = true; });
            $this->fail('The operation must fail.');
        } catch (ConnectionException $error) {
            $this->assertNotSame('', $error->getMessage());
        }
        $this->assertFalse($called);
        $this->assertSame(0, (int)$this->db->fetchValue("SELECT COUNT(*) FROM `{$this->table}`"));
        $this->assertCount(1, $this->manager()->getPending());
        if ($failure !== 'first_sql') {
            // SQL effects survive both a later SQL error and a failed history insert.
            $this->assertSame(1, (int)$this->db->fetchValue("SELECT COUNT(*) FROM `{$this->effects}`"));
        }
        $this->assertLockReleased();
    }

    public function failures(): array
    {
        return [['first_sql'], ['later_sql'], ['history']];
    }

    public function test_callback_failure_keeps_history_and_releases_lock(): void
    {
        $this->migration('20260714120000', 'SELECT 1');
        $failure = new \RuntimeException('Callback failed');
        try {
            $this->manager()->runPending(static function () use ($failure): void { throw $failure; });
            $this->fail('Callback must fail.');
        } catch (\RuntimeException $error) {
            $this->assertSame($failure, $error);
        }
        $this->assertSame([], $this->manager()->getPending());
        $this->assertLockReleased();
    }

    public function test_two_sessions_conflict_before_history_creation_and_can_retry(): void
    {
        $this->other->fetchValue('SELECT GET_LOCK(?, 0)', [$this->lockName()]);
        $manager = $this->manager();
        try {
            $manager->runPending();
            $this->fail('A competing session must not run migrations.');
        } catch (MigrationException $error) {
            $this->assertSame('Another process is currently running migrations.', $error->getMessage());
            $this->assertFalse((new MigrationHistory($this->db, $this->table))->exists());
            // A different history table has its own independent lock.
            $independentTable = $this->table . '_other';
            try {
                $this->assertSame([], (new MigrationManager($this->db, $this->temporaryDirectory,
                    $independentTable))->runPending());
            } finally {
                $this->db->execute("DROP TABLE IF EXISTS `$independentTable`");
            }
        } finally {
            $this->other->fetchValue('SELECT RELEASE_LOCK(?)', [$this->lockName()]);
        }
        $this->assertSame([], $manager->runPending());
        $this->assertLockReleased();
    }

    public function test_second_manager_is_blocked_during_the_first_managers_callback(): void
    {
        $this->migration('20260714120000', 'SELECT 1');
        $second = $this->manager($this->other);
        $this->manager()->runPending(function () use ($second): void {
            // Both managers use the public API while the first operation remains active.
            try {
                $second->runPending();
                $this->fail('The second manager must not acquire the first manager\'s lock.');
            } catch (MigrationException $error) {
                $this->assertSame('Another process is currently running migrations.', $error->getMessage());
            }
        });
        $this->assertSame([], $second->runPending());
        $this->assertLockReleased();
    }

    public function test_rollback_uses_versions_and_reuses_files_across_managers(): void
    {
        $this->db->execute("CREATE TABLE `{$this->effects}` (id INT PRIMARY KEY)");
        $this->migration('20260714120002', "INSERT INTO `{$this->effects}` VALUES (2)",
            '$this->execute(' . var_export("DELETE FROM `{$this->effects}` WHERE id = 2", true) . ');');
        $first = $this->manager();
        $first->runPending();
        // An older version is applied later, so execution order differs from version order.
        $this->migration('20260714120001', "INSERT INTO `{$this->effects}` VALUES (1)",
            '$this->execute(' . var_export("DELETE FROM `{$this->effects}` WHERE id = 1", true) . ');');
        $first->runPending();
        $second = $this->manager($this->other);
        $versions = [];
        $files = $second->rollback(5, function ($file, $ms) use (&$versions): void {
            $versions[] = $file->getVersion();
            $this->assertSame(0, (int)$this->db->fetchValue(
                "SELECT COUNT(*) FROM `{$this->table}` WHERE version = ?", [$file->getVersion()]));
            $this->assertSame((int)$this->other->fetchValue('SELECT CONNECTION_ID()'),
                (int)$this->db->fetchValue('SELECT IS_USED_LOCK(?)', [$this->lockName()]));
            $this->assertIsInt($ms);
            $this->assertGreaterThanOrEqual(0, $ms);
            try {
                $this->manager()->rollback();
                $this->fail('Concurrent rollback must be blocked.');
            } catch (MigrationException $error) {
                $this->assertSame('Another process is currently running migrations.', $error->getMessage());
            }
        });
        $this->assertSame(['20260714120002', '20260714120001'], $versions);
        $this->assertSame($versions, array_map(static function ($file) { return $file->getVersion(); }, $files));
        $this->assertSame(0, (int)$this->db->fetchValue("SELECT COUNT(*) FROM `{$this->effects}`"));
        $this->assertSame([], $second->rollback());
        $this->assertCount(2, $first->getPending());
        $this->assertLockReleased();
    }

    /** @dataProvider rollbackFailures */
    public function test_rollback_stops_at_failure_and_preserves_failed_history(string $failure): void
    {
        $this->db->execute("CREATE TABLE `{$this->effects}` (id INT PRIMARY KEY)");
        $down = '$this->execute(' . var_export("INSERT INTO `{$this->effects}` VALUES (1)", true) . ');';
        if ($failure === 'irreversible') {
            $down = 'throw new \\Kgkg\\MigrationManager\\IrreversibleMigrationException("Data cannot be restored.");';
        } elseif ($failure === 'sql') {
            $down .= '$this->execute("INVALID SQL");';
        }
        $this->migration('20260714120000', 'SELECT 1');
        $path = $this->migration('20260714120001', 'SELECT 1', $down);
        $this->migration('20260714120002', 'SELECT 1');
        $manager = $this->manager();
        $manager->runPending();
        if ($failure === 'missing') {
            unlink($path);
        } elseif ($failure === 'delete') {
            $this->db->execute("CREATE TRIGGER `{$this->table}_guard` BEFORE DELETE ON `{$this->table}` "
                . "FOR EACH ROW BEGIN IF OLD.version = '20260714120001' THEN "
                . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'History delete blocked'; END IF; END");
        }
        $callbacks = [];
        try {
            $manager->rollback(3, static function ($file) use (&$callbacks): void {
                $callbacks[] = $file->getVersion();
            });
            $this->fail('Rollback must stop at the failed migration.');
        } catch (MigrationException $error) {
            $this->assertNotSame('', $error->getMessage());
            if ($failure === 'irreversible') {
                $this->assertInstanceOf(\Kgkg\MigrationManager\IrreversibleMigrationException::class, $error);
                $this->assertSame('Data cannot be restored.', $error->getMessage());
            }
        }
        $this->assertSame(['20260714120002'], $callbacks);
        $this->assertSame([
            ['version' => '20260714120000'], ['version' => '20260714120001'],
        ], $this->db->fetchAll("SELECT version FROM `{$this->table}` ORDER BY version"));
        $this->assertSame(in_array($failure, ['sql', 'delete'], true) ? 1 : 0,
            (int)$this->db->fetchValue("SELECT COUNT(*) FROM `{$this->effects}`"));
        $this->assertLockReleased();
    }

    public function rollbackFailures(): array
    {
        return [['irreversible'], ['missing'], ['sql'], ['delete']];
    }

    public function test_default_rollback_accepts_empty_down_and_removes_only_highest_version(): void
    {
        $this->migration('20260714120000', 'SELECT 1');
        $this->migration('20260714120001', 'SELECT 1');
        $manager = $this->manager();
        $manager->runPending();
        $files = $manager->rollback();
        $this->assertCount(1, $files);
        $this->assertSame('20260714120001', $files[0]->getVersion());
        $this->assertSame([['version' => '20260714120000']],
            $this->db->fetchAll("SELECT version FROM `{$this->table}`"));
        $this->assertLockReleased();
    }
}
