<?php

namespace Kgkg\MigrationManager\Tests\Unit;

use Kgkg\MigrationManager\Connection\ConnectionException;
use Kgkg\MigrationManager\Connection\ConnectionInterface;
use Kgkg\MigrationManager\MigrationException;
use Kgkg\MigrationManager\MigrationManager;

final class MigrationRunTest extends MigrationManagerTestCase
{
    /** @dataProvider invalidOptions */
    public function test_rejects_invalid_options_without_database_access(string $table, int $timeout): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->expects($this->never())->method('getDatabaseName');
        $db->expects($this->never())->method('fetchValue');
        $this->expectException(MigrationException::class);
        new MigrationManager($db, $this->temporaryDirectory, $table, $timeout);
    }

    public function invalidOptions(): array
    {
        return [['', 0], ['1table', 0], ['db.table', 0], ['a`b', 0], ["name\n", 0],
            [str_repeat('a', 65), 0], ['história', 0], ['history', -1]];
    }

    /** @dataProvider lockFailures */
    public function test_failed_lock_does_not_write_or_release($result): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('getDatabaseName')->willReturn('test_database');
        $db->expects($this->once())->method('fetchValue')->with('SELECT GET_LOCK(?, ?)',
            ['migration_manager_' . sha1("test_database\0custom_history"), 3])->willReturn($result);
        $db->expects($this->never())->method('execute');
        $db->expects($this->never())->method('fetchAll');
        $this->expectException(MigrationException::class);
        (new MigrationManager($db, $this->temporaryDirectory, 'custom_history', 3))->runPending();
    }

    public function lockFailures(): array
    {
        return [[0], [null]];
    }

    public function test_acquisition_exception_is_preserved_without_release(): void
    {
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('getDatabaseName')->willReturn('unit_database');
        $failure = new ConnectionException('Lock query failed');
        $db->expects($this->once())->method('fetchValue')->with('SELECT GET_LOCK(?, ?)',
            $this->isType('array'))->willThrowException($failure);
        $db->expects($this->never())->method('execute');
        $this->expectExceptionObject($failure);
        (new MigrationManager($db, $this->temporaryDirectory))->runPending();
    }

    /** @dataProvider releaseResults */
    public function test_unsuccessful_release_is_reported_and_manager_state_is_reset($releaseResult): void
    {
        $table = '_' . str_repeat('a', 63);
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('getDatabaseName')->willReturn('unit_database');
        $lock = 'migration_manager_' . sha1("unit_database\0" . $table);
        $db->expects($this->exactly(4))->method('fetchValue')
            ->withConsecutive(['SELECT GET_LOCK(?, ?)', [$lock, 1]], ['SELECT RELEASE_LOCK(?)', [$lock]],
                ['SELECT GET_LOCK(?, ?)', [$lock, 1]], ['SELECT RELEASE_LOCK(?)', [$lock]])
            ->willReturnOnConsecutiveCalls(1, $releaseResult, 1, 1);
        $db->expects($this->exactly(2))->method('execute')->with(
            $this->stringContains('CREATE TABLE IF NOT EXISTS `' . $table . '`'));
        $db->method('fetchAll')->willReturn([]);
        $manager = new MigrationManager($db, $this->temporaryDirectory, $table, 1);
        try {
            $manager->runPending();
            $this->fail('An unsuccessful release must be reported.');
        } catch (MigrationException $error) {
            $this->assertSame('Unable to release the migration lock.', $error->getMessage());
        }
        $this->assertSame([], $manager->runPending());
    }

    public function releaseResults(): array
    {
        return [[0], [null]];
    }

    public function test_loader_error_releases_lock(): void
    {
        $this->writeMigrationFile('20260714120000_unit_missing_migration_class.php');
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('getDatabaseName')->willReturn('unit_database');
        $db->expects($this->exactly(2))->method('fetchValue')->withConsecutive(
            ['SELECT GET_LOCK(?, ?)', $this->isType('array')],
            ['SELECT RELEASE_LOCK(?)', $this->isType('array')]
        )->willReturn(1);
        $db->method('fetchAll')->willReturn([]);
        $db->expects($this->never())->method('executePrepared');
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('must declare class');
        (new MigrationManager($db, $this->temporaryDirectory))->runPending();
    }

    /** @dataProvider failurePoints */
    public function test_run_order_and_cleanup(?string $failurePoint, bool $releaseFails): void
    {
        $class = 'RunUnit' . bin2hex(random_bytes(8));
        $name = strtolower($class);
        $this->writeMigrationFile('20260714120000_' . $name . '.php',
            '<?php final class ' . $class . ' extends \\Kgkg\\MigrationManager\\AbstractMigration {'
            . 'public function up(): void {$this->execute("MIGRATION SQL");}'
            . 'public function down(): void {}}');
        $events = [];
        $failure = new ConnectionException('Primary failure');
        $releaseFailure = new ConnectionException('Release failure');
        $db = $this->createMock(ConnectionInterface::class);
        $db->method('getDatabaseName')->willReturn('unit_database');
        $lockName = 'migration_manager_' . sha1("unit_database\0schema_migrations");
        $db->expects($this->exactly(2))->method('fetchValue')->willReturnCallback(
            function ($sql, $parameters) use (&$events, $releaseFails, $releaseFailure, $lockName) {
                if ($sql === 'SELECT GET_LOCK(?, ?)') {
                    $this->assertSame([$lockName, 0], $parameters);
                    $events[] = 'lock';
                } else {
                    $this->assertSame('SELECT RELEASE_LOCK(?)', $sql);
                    $this->assertSame([$lockName], $parameters);
                    $events[] = 'release';
                    if ($releaseFails) {
                        throw $releaseFailure;
                    }
                }
                return 1;
            }
        );
        $db->method('execute')->willReturnCallback(
            function ($sql) use (&$events, $failurePoint, $failure): void {
                $point = $sql === 'MIGRATION SQL' ? 'up' : 'create';
                $events[] = $point;
                if ($failurePoint === $point) {
                    throw $failure;
                }
            }
        );
        $db->method('fetchAll')->willReturnCallback(
            function () use (&$events, $failurePoint, $failure): array {
                $events[] = 'read';
                if ($failurePoint === 'read') {
                    throw $failure;
                }
                return [];
            }
        );
        $db->method('executePrepared')->willReturnCallback(
            function ($sql, $parameters) use (&$events, $failurePoint, $failure, $name): void {
                $this->assertStringContainsString('INSERT INTO `schema_migrations`', $sql);
                $this->assertSame('20260714120000', $parameters[0]);
                $this->assertSame($name, $parameters[1]);
                $this->assertIsInt($parameters[2]);
                $this->assertGreaterThanOrEqual(0, $parameters[2]);
                $events[] = 'record';
                if ($failurePoint === 'record') {
                    throw $failure;
                }
            }
        );
        try {
            $files = (new MigrationManager($db, $this->temporaryDirectory))->runPending(
                function ($file, $ms) use (&$events, $failurePoint, $failure, $name): void {
                    $this->assertSame('record', end($events));
                    $this->assertSame($name, $file->getName());
                    $this->assertIsInt($ms);
                    $this->assertGreaterThanOrEqual(0, $ms);
                    $events[] = 'callback';
                    if ($failurePoint === 'callback') {
                        throw $failure;
                    }
                }
            );
            $this->assertNull($failurePoint);
            $this->assertFalse($releaseFails);
            $this->assertCount(1, $files);
        } catch (ConnectionException $error) {
            $this->assertSame($failurePoint === null ? $releaseFailure : $failure, $error);
        }
        $expected = ['lock', 'create', 'read', 'up', 'record', 'callback'];
        if ($failurePoint !== null) {
            $expected = array_slice($expected, 0, array_search($failurePoint, $expected, true) + 1);
        }
        $expected[] = 'release';
        $this->assertSame($expected, $events);
    }

    public function failurePoints(): array
    {
        $cases = [[null, false], [null, true]];
        foreach (['create', 'read', 'up', 'record', 'callback'] as $point) {
            $cases[] = [$point, false];
            $cases[] = [$point, true];
        }
        return $cases;
    }
}
