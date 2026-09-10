<?php

namespace Kgkg\MigrationManager\Tests\Integration;

use Kgkg\MigrationManager\Connection\MysqliConnection;
use Kgkg\MigrationManager\MigrationHistory;
use Kgkg\MigrationManager\Tests\Unit\MigrationManagerTestCase;
use Kgkg\MigrationManager\Tests\Support\ConsoleProcess;

final class ConsoleApplicationTest extends MigrationManagerTestCase
{
    use ConsoleProcess;

    private MysqliConnection $db;
    private string $table;
    private string $effects;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = MysqliConnection::connect([
            'host' => getenv('MIGRATION_MANAGER_TEST_HOST'),
            'port' => (int)getenv('MIGRATION_MANAGER_TEST_PORT'),
            'database' => getenv('MIGRATION_MANAGER_TEST_DATABASE'),
            'username' => getenv('MIGRATION_MANAGER_TEST_USERNAME'),
            'password' => getenv('MIGRATION_MANAGER_TEST_PASSWORD'),
        ]);
        $this->table = 'cli_history_' . bin2hex(random_bytes(6));
        $this->effects = 'cli_effects_' . bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory . '/settings');
        $this->success(['init']);
        file_put_contents($this->temporaryDirectory . '/settings/bootstrap.php', '<?php define("CLI_BOOTSTRAPPED", true);');
        file_put_contents($this->temporaryDirectory . '/settings/custom.php', str_replace('__TABLE__', $this->table, <<<'PHP'
<?php
return [
    'migrations_path' => 'db/migrations',
    'bootstrap' => 'bootstrap.php',
    'table_name' => '__TABLE__',
    'lock_timeout' => 0,
    'connection' => static function () {
        if (!defined('CLI_BOOTSTRAPPED')) {
            throw new RuntimeException('Bootstrap must precede the connection factory.');
        }
        return \Kgkg\MigrationManager\Connection\MysqliConnection::connect([
            'host' => getenv('MIGRATION_MANAGER_TEST_HOST'),
            'port' => (int)getenv('MIGRATION_MANAGER_TEST_PORT'),
            'database' => getenv('MIGRATION_MANAGER_TEST_DATABASE'),
            'username' => getenv('MIGRATION_MANAGER_TEST_USERNAME'),
            'password' => getenv('MIGRATION_MANAGER_TEST_PASSWORD'),
        ]);
    },
];
PHP
        ));
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->db)) {
                $this->db->execute("DROP TABLE IF EXISTS `{$this->table}`, `{$this->effects}`");
            }
        } finally {
            unset($this->db);
            parent::tearDown();
        }
    }

    private function invoke(array $arguments): array
    {
        return $this->cli(array_merge($arguments, ['--config=settings/custom.php']));
    }

    private function success(array $arguments): string
    {
        [$code, $output, $error] = $this->invoke($arguments);
        $this->assertSame(0, $code, $error);
        $this->assertSame('', $error);
        return $output;
    }

    private function migration(string $name, string $up, string $down = ''): string
    {
        $this->success(['create', $name]);
        $files = glob($this->temporaryDirectory . '/settings/db/migrations/*_' . $name . '.php');
        $this->assertCount(1, $files);
        $contents = file_get_contents($files[0]);
        $contents = str_replace("// Apply changes with \$this->execute('CREATE TABLE ...');", $up, $contents);
        $contents = str_replace("// Revert changes with \$this->execute('DROP TABLE ...');", $down, $contents);
        file_put_contents($files[0], $contents);
        return $files[0];
    }

    private function sql(string $sql): string
    {
        return '$this->execute(' . var_export($sql, true) . ');';
    }

    private function lockName(): string
    {
        return 'migration_manager_' . sha1($this->db->getDatabaseName() . "\0" . $this->table);
    }

    public function testFullCycleWithDefaultAndMultipleRollbackSteps(): void
    {
        $this->assertStringContainsString('No pending migrations.', $this->success(['show']));
        $this->assertFalse((new MigrationHistory($this->db, $this->table))->exists());
        $this->migration('create_items', $this->sql("CREATE TABLE `{$this->effects}` (id INT PRIMARY KEY)"),
            $this->sql("DROP TABLE `{$this->effects}`"));
        $this->migration('first_item', $this->sql("INSERT INTO `{$this->effects}` VALUES (1)"),
            $this->sql("DELETE FROM `{$this->effects}` WHERE id=1"));
        $this->migration('second_item', $this->sql("INSERT INTO `{$this->effects}` VALUES (2)"),
            $this->sql("DELETE FROM `{$this->effects}` WHERE id=2"));
        $this->assertStringContainsString('Total: 3', $this->success(['show']));
        $output = $this->success(['run']);
        $this->assertMatchesRegularExpression('/Executed [0-9]{14}_create_items \([0-9]+ ms\)/', $output);
        $this->assertMatchesRegularExpression('/Executed migrations: 3 \([0-9]+ ms total\)/', $output);
        $this->assertSame(3, (int)$this->db->fetchValue("SELECT COUNT(*) FROM `{$this->table}`"));
        $this->assertStringContainsString('No pending migrations.', $this->success(['show']));
        $this->assertStringContainsString('Executed migrations: 0', $this->success(['run']));
        $output = $this->success(['rollback']);
        $this->assertMatchesRegularExpression('/Rolled back [0-9]{14}_second_item \([0-9]+ ms\)/', $output);
        $this->assertStringContainsString('Rolled back migrations: 1', $output);
        $this->assertSame([['id' => 1]], $this->db->fetchAll("SELECT id FROM `{$this->effects}`"));
        $this->assertStringContainsString('Rolled back migrations: 2', $this->success(['rollback', '--steps', '2']));
        $this->assertSame(0, (int)$this->db->fetchValue("SELECT COUNT(*) FROM `{$this->table}`"));
        $this->assertStringContainsString('No migrations to roll back.', $this->success(['rollback', '--steps=2']));
        $this->assertStringContainsString('Total: 3', $this->success(['show']));
    }

    public function testShowDoesNotAcquireLockOrCreateHistoryAndRunReportsContention(): void
    {
        $this->migration('pending_item', 'throw new RuntimeException("Must not execute");');
        $this->db->fetchValue('SELECT GET_LOCK(?, 0)', [$this->lockName()]);
        try {
            $this->assertStringContainsString('Total: 1', $this->success(['show']));
            $this->assertFalse((new MigrationHistory($this->db, $this->table))->exists());
            [$code, $output, $error] = $this->invoke(['run']);
            $this->assertSame(1, $code);
            $this->assertSame('', $output);
            $this->assertStringContainsString('Another process is currently running migrations.', $error);
            $this->assertFalse((new MigrationHistory($this->db, $this->table))->exists());
        } finally {
            $this->db->fetchValue('SELECT RELEASE_LOCK(?)', [$this->lockName()]);
        }
    }

    public function testSqlFailurePreservesSuccessfulProgressAndDoesNotPrintSuccessSummary(): void
    {
        $this->migration('create_items', $this->sql("CREATE TABLE `{$this->effects}` (id INT)"));
        $this->migration('broken_item', $this->sql("INSERT INTO `{$this->effects}` VALUES (1); INVALID SQL"));
        [$code, $output, $error] = $this->invoke(['run']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('_create_items (', $output);
        $this->assertStringNotContainsString('Executed migrations:', $output);
        $this->assertStringNotContainsString('_broken_item (', $output);
        $this->assertStringContainsString('Migration error:', $error);
        $this->assertSame(1, (int)$this->db->fetchValue("SELECT COUNT(*) FROM `{$this->table}`"));
        $this->assertSame(1, (int)$this->db->fetchValue("SELECT COUNT(*) FROM `{$this->effects}`"));
        $this->assertSame(1, (int)$this->db->fetchValue('SELECT IS_FREE_LOCK(?)', [$this->lockName()]));
    }

    public function testIrreversibleAndMissingMigrationErrorsKeepHistory(): void
    {
        $path = $this->migration('permanent_item', '', 'throw new \\Kgkg\\MigrationManager\\IrreversibleMigrationException();');
        $this->success(['run']);
        [$code, $output, $error] = $this->invoke(['rollback']);
        $this->assertSame(1, $code);
        $this->assertSame('', $output);
        $this->assertStringContainsString('This migration cannot be reversed.', $error);
        $this->assertSame(1, (int)$this->db->fetchValue("SELECT COUNT(*) FROM `{$this->table}`"));
        unlink($path);
        [$code, , $error] = $this->invoke(['rollback']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Missing file for applied migration', $error);
        $this->assertSame(1, (int)$this->db->fetchValue("SELECT COUNT(*) FROM `{$this->table}`"));
        $this->assertSame(1, (int)$this->db->fetchValue('SELECT IS_FREE_LOCK(?)', [$this->lockName()]));
    }

    public function testConnectionFailureUsesStderr(): void
    {
        file_put_contents($this->temporaryDirectory . '/settings/custom.php', <<<'PHP'
<?php
return ['migrations_path' => 'db/migrations', 'connection' => static function () {
    return \Kgkg\MigrationManager\Connection\MysqliConnection::connect([
        'host' => getenv('MIGRATION_MANAGER_TEST_HOST'),
        'port' => (int)getenv('MIGRATION_MANAGER_TEST_PORT'),
        'database' => getenv('MIGRATION_MANAGER_TEST_DATABASE'),
        'username' => 'migration_manager_test_invalid', 'password' => 'invalid',
    ]);
}];
PHP
        );
        foreach (['show', 'run', 'rollback'] as $command) {
            [$code, $output, $error] = $this->invoke([$command]);
            $this->assertSame(1, $code);
            $this->assertSame('', $output);
            $this->assertStringContainsString('Migration error:', $error);
        }
        $this->assertFalse((new MigrationHistory($this->db, $this->table))->exists());
    }
}
