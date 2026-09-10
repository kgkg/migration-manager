<?php

namespace Kgkg\MigrationManager\Tests\Unit;

use Kgkg\MigrationManager\AbstractMigration;
use Kgkg\MigrationManager\Connection\ConnectionException;
use Kgkg\MigrationManager\Connection\ConnectionInterface;
use PHPUnit\Framework\TestCase;

final class AbstractMigrationTest extends TestCase
{
    public function test_delegates_sql_and_metadata_to_the_same_connection(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())->method('execute')->with("SELECT ';'; SELECT 2");
        $connection->expects($this->once())->method('hasColumn')->with('users', 'email')->willReturn(true);
        $migration = $this->migration($connection);
        $migration->up();
        $this->assertTrue($migration->columnExists());
        $this->assertSame($connection, $migration->connection());
    }

    public function test_preserves_adapter_exception(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $exception = new ConnectionException('SQL failed');
        $connection->method('execute')->willThrowException($exception);
        $this->expectExceptionObject($exception);
        $this->migration($connection)->up();
    }

    private function migration(ConnectionInterface $connection): AbstractMigration
    {
        return new class($connection) extends AbstractMigration {
            public function up(): void
            {
                $this->execute("SELECT ';'; SELECT 2");
            }

            public function down(): void
            {
            }

            public function columnExists(): bool
            {
                return $this->hasColumn('users', 'email');
            }

            public function connection(): ConnectionInterface
            {
                return $this->getDatabase();
            }
        };
    }
}
