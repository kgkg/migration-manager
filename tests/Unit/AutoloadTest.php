<?php

namespace Kgkg\MigrationManager\Tests\Unit;

use Kgkg\MigrationManager\Connection\ConnectionException;
use Kgkg\MigrationManager\Connection\ConnectionInterface;
use Kgkg\MigrationManager\MigrationException;
use Kgkg\MigrationManager\MigrationFile;
use PHPUnit\Framework\TestCase;

final class AutoloadTest extends TestCase
{
    public function testPublicTypesLoadWithoutApplicationBootstrap(): void
    {
        self::assertFalse(class_exists('Database', false));
        self::assertTrue(interface_exists(ConnectionInterface::class));
        $file = new MigrationFile('20260714120000', 'create_users', 'CreateUsers', '/migrations/file.php');
        self::assertSame('20260714120000', $file->getVersion());
        self::assertSame('create_users', $file->getName());
        self::assertSame('CreateUsers', $file->getClassName());
        self::assertSame('/migrations/file.php', $file->getPath());
    }

    public function testDatabaseFailureRetainsItsCauseAndPackageCatchType(): void
    {
        $cause = new \RuntimeException('Driver failure', 123);
        $failure = new ConnectionException('SQL execution failed.', 123, $cause);
        self::assertInstanceOf(MigrationException::class, $failure);
        self::assertSame($cause, $failure->getPrevious());
        self::assertSame(123, $failure->getCode());
        self::assertSame('SQL execution failed.', $failure->getMessage());
    }
}
