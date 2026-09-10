<?php

namespace Kgkg\MigrationManager\Tests\Unit;

use Kgkg\MigrationManager\Connection\ConnectionInterface;
use Kgkg\MigrationManager\MigrationManager;

final class MigrationManagerTest extends MigrationManagerTestCase
{
    public function test_should_return_all_migrations_when_history_table_does_not_exist(): void
    {
        // given
        $this->writeMigrationFile('20260714120001_add_user_profiles.php');
        $this->writeMigrationFile('20260714120000_create_users.php');

        $database = $this->createConnectionMock();
        $database->expects($this->never())->method('execute');
        $database->expects($this->never())->method('executePrepared');
        $database->expects($this->never())->method('getDatabaseName');
        $database->expects($this->once())
            ->method('fetchValue')
            ->with($this->stringContains('`table_name` = ?'), ['schema_migrations'])
            ->willReturn(0);
        $database->expects($this->never())->method('fetchAll');

        // when
        $pending = (new MigrationManager($database, $this->temporaryDirectory))->getPending();

        // then
        $this->assertSame(
            ['20260714120000', '20260714120001'],
            array_map(static function ($migration): string {
                return $migration->getVersion();
            }, $pending)
        );
    }

    public function test_should_return_only_migrations_missing_from_history_table(): void
    {
        // given
        $this->writeMigrationFile('20260714120000_create_users.php');
        $this->writeMigrationFile('20260714120001_add_user_profiles.php');
        $this->writeMigrationFile('20260714120002_create_clans.php');

        $database = $this->createConnectionMock();
        $database->expects($this->never())->method('execute');
        $database->expects($this->never())->method('executePrepared');
        $database->expects($this->never())->method('getDatabaseName');
        $database->expects($this->once())->method('fetchValue')->willReturn(1);
        $database->expects($this->once())
            ->method('fetchAll')
            ->willReturn([
                ['version' => '20260714120000'],
                ['version' => '20260714120002'],
            ]);

        // when
        $pending = (new MigrationManager($database, $this->temporaryDirectory))->getPending();

        // then
        $this->assertCount(1, $pending);
        $this->assertSame('20260714120001', $pending[0]->getVersion());
        $this->assertSame('add_user_profiles', $pending[0]->getName());
    }
}
