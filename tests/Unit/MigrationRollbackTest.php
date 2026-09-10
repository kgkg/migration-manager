<?php

namespace Kgkg\MigrationManager\Tests\Unit;

use Kgkg\MigrationManager\Connection\ConnectionException;
use Kgkg\MigrationManager\Connection\ConnectionInterface;
use Kgkg\MigrationManager\IrreversibleMigrationException;
use Kgkg\MigrationManager\MigrationException;
use Kgkg\MigrationManager\MigrationManager;

final class MigrationRollbackTest extends MigrationManagerTestCase
{
    public function test_generated_down_preserves_history_and_releases_lock(): void
    {
        $path = (new \Kgkg\MigrationManager\MigrationCreator($this->temporaryDirectory))
            ->create('generated rollback ' . bin2hex(random_bytes(6)));
        $version = substr(basename($path), 0, 14);
        $db = $this->createConnectionMock();
        $db->method('getDatabaseName')->willReturn('unit_database');
        $db->method('fetchAll')->willReturn([['version' => $version]]);
        $db->expects($this->exactly(2))->method('fetchValue')->withConsecutive(
            ['SELECT GET_LOCK(?, ?)', $this->isType('array')],
            ['SELECT RELEASE_LOCK(?)', $this->isType('array')]
        )->willReturn(1);
        $db->expects($this->never())->method('executePrepared');
        $this->expectException(IrreversibleMigrationException::class);
        (new MigrationManager($db, $this->temporaryDirectory))->rollback();
    }

    /** @dataProvider invalidSteps */
    public function test_invalid_steps_do_not_access_database(int $steps): void
    {
        $db = $this->createConnectionMock();
        $db->expects($this->never())->method('getDatabaseName');
        $db->expects($this->never())->method('fetchValue');
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('must be greater than zero');
        (new MigrationManager($db, $this->temporaryDirectory))->rollback($steps);
    }

    public function invalidSteps(): array
    {
        return [[0], [-1], [PHP_INT_MIN]];
    }

    /** @dataProvider failures */
    public function test_failure_preserves_history_and_releases_lock(string $point, bool $releaseFails): void
    {
        $name = 'rollback' . bin2hex(random_bytes(8));
        $down = $point === 'irreversible'
            ? 'throw new \\Kgkg\\MigrationManager\\IrreversibleMigrationException();'
            : '$this->execute("DOWN SQL");';
        if ($point !== 'missing') {
            $this->writeMigrationFile('20260714120000_' . $name . '.php',
                '<?php final class ' . ucfirst($name) . ' extends \\Kgkg\\MigrationManager\\AbstractMigration {'
                . 'public function up(): void {} public function down(): void {' . $down . '}}');
        }
        $db = $this->createConnectionMock();
        $db->method('getDatabaseName')->willReturn('unit_database');
        $db->expects($this->exactly(2))->method('fetchValue')->withConsecutive(
            ['SELECT GET_LOCK(?, ?)', $this->isType('array')],
            ['SELECT RELEASE_LOCK(?)', $this->isType('array')]
        )->willReturnCallback(static function ($sql) use ($releaseFails) {
            if ($releaseFails && $sql === 'SELECT RELEASE_LOCK(?)') {
                throw new ConnectionException('Release failed');
            }
            return 1;
        });
        $db->method('fetchAll')->willReturn([['version' => '20260714120000']]);
        $failure = new ConnectionException('Primary failure');
        $db->method('execute')->willReturnCallback(static function ($sql) use ($point, $failure): void {
            if ($sql === 'DOWN SQL' && $point === 'down') {
                throw $failure;
            }
        });
        if (in_array($point, ['delete', 'callback'], true)) {
            $expectation = $db->expects($this->once())->method('executePrepared')
                ->with('DELETE FROM `schema_migrations` WHERE `version` = ?', ['20260714120000']);
            if ($point === 'delete') {
                $expectation->willThrowException($failure);
            }
        } else {
            $db->expects($this->never())->method('executePrepared');
        }
        $called = false;
        try {
            (new MigrationManager($db, $this->temporaryDirectory))->rollback(1,
                static function () use (&$called, $failure): void { $called = true; throw $failure; });
            $this->fail('Rollback must fail.');
        } catch (MigrationException $error) {
            if ($point === 'missing') {
                $this->assertSame('Missing file for applied migration 20260714120000.', $error->getMessage());
            } elseif ($point === 'irreversible') {
                $this->assertInstanceOf(IrreversibleMigrationException::class, $error);
                $this->assertSame('This migration cannot be reversed.', $error->getMessage());
            } else {
                $this->assertSame($failure, $error);
            }
        }
        $this->assertSame($point === 'callback', $called);
    }

    public function failures(): array
    {
        $cases = [];
        foreach (['missing', 'irreversible', 'down', 'delete', 'callback'] as $point) {
            $cases[] = [$point, false];
            $cases[] = [$point, true];
        }
        return $cases;
    }
}
