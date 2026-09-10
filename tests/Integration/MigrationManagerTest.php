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

    private function migration(string $version, string $sql): string
    {
        $name = 'integration_' . bin2hex(random_bytes(8));
        $class = str_replace('_', '', ucwords($name, '_'));
        return $this->writeMigrationFile($version . '_' . $name . '.php',
            '<?php final class ' . $class . ' extends \\Kgkg\\MigrationManager\\AbstractMigration {'
            . 'public function up(): void {$this->execute(' . var_export($sql, true) . ');}'
            . 'public function down(): void {}}');
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
        $this->migration('20260714120000', 'INVALID SQL');
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
            $this->db->execute("ALTER TABLE `{$this->table}` DROP COLUMN execution_time_ms");
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
}
