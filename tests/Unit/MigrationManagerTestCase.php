<?php

namespace Kgkg\MigrationManager\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

abstract class MigrationManagerTestCase extends TestCase
{
    protected function historyResult(string $sql, array $rows = []): array
    {
        if (strpos($sql, 'information_schema.columns c') !== false) {
            $result = [];
            foreach (['version' => 'varchar(14)', 'migration_name' => 'varchar(255)',
                'executed_at' => 'datetime', 'execution_time_ms' => 'int unsigned'] as $name => $type) {
                $result[] = ['COLUMN_NAME' => $name, 'COLUMN_TYPE' => $type, 'IS_NULLABLE' => 'NO',
                    'EXTRA' => '', 'ENGINE' => 'InnoDB', 'CHARACTER_SET_NAME' => 'utf8mb4'];
            }
            return $result;
        }
        if (strpos($sql, 'information_schema.statistics') !== false) {
            return [['INDEX_NAME' => 'PRIMARY', 'COLUMN_NAME' => 'version', 'SEQ_IN_INDEX' => 1, 'SUB_PART' => null]];
        }
        return $rows;
    }

    protected function createConnectionMock(): \PHPUnit\Framework\MockObject\MockObject
    {
        $mock = $this->createMock(\Kgkg\MigrationManager\Connection\ConnectionInterface::class);
        $mock->method('getMigrationLockName')->willReturnCallback(static function (string $table) use ($mock): string {
            return 'migration_manager_' . sha1($mock->getDatabaseName() . "\0" . $table);
        });
        return $mock;
    }

    protected string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'migration_manager_' . bin2hex(random_bytes(8));

        if (mkdir($this->temporaryDirectory, 0777, true) === false) {
            throw new RuntimeException("Unable to create directory {$this->temporaryDirectory}");
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->temporaryDirectory)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $this->temporaryDirectory,
                    FilesystemIterator::SKIP_DOTS
                ),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            /** @var SplFileInfo $item */
            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    rmdir($item->getPathname());
                } else {
                    unlink($item->getPathname());
                }
            }

            rmdir($this->temporaryDirectory);
        }

        parent::tearDown();
    }

    protected function writeMigrationFile(string $fileName, string $contents = '<?php'): string
    {
        $path = $this->temporaryDirectory . DIRECTORY_SEPARATOR . $fileName;
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Unable to create file {$path}");
        }

        return $path;
    }
}
