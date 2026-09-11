<?php

namespace Kgkg\MigrationManager;

use Kgkg\MigrationManager\Connection\ConnectionInterface;

/** Stores migration history independently of the connection driver. */
final class MigrationHistory
{
    private ConnectionInterface $connection;
    private string $tableName;

    public function __construct(ConnectionInterface $connection, string $tableName = 'schema_migrations')
    {
        if (strlen($tableName) > 64 || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $tableName) !== 1) {
            throw new MigrationException('The history table name must be a valid SQL identifier of at most 64 characters.');
        }
        $this->connection = $connection;
        $this->tableName = $tableName;
    }

    public function ensureExists(): void
    {
        $this->connection->execute(
            'CREATE TABLE IF NOT EXISTS `' . $this->tableName . '` ('
            . '`version` VARCHAR(14) NOT NULL, '
            . '`migration_name` VARCHAR(255) NOT NULL, '
            . '`executed_at` DATETIME NOT NULL, '
            . '`execution_time_ms` INT UNSIGNED NOT NULL, '
            . 'PRIMARY KEY (`version`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    public function exists(): bool
    {
        return (int)$this->connection->fetchValue(
            'SELECT COUNT(*) FROM `information_schema`.`tables` '
            . 'WHERE `table_schema` = DATABASE() AND `table_name` = ?',
            [$this->tableName]
        ) === 1;
    }

    /** @return array<string, bool> */
    public function getAppliedVersions(): array
    {
        return array_fill_keys(array_keys($this->getAppliedMigrations()), true);
    }

    /** @return array<string, string> Version to the name originally recorded. */
    public function getAppliedMigrations(): array
    {
        $this->assertCompatibleSchema();
        $versions = [];
        foreach ($this->connection->fetchAll(
            'SELECT `version`, `migration_name` FROM `' . $this->tableName . '` ORDER BY `version` ASC'
        ) as $row) {
            $version = (string)$row['version'];
            $name = (string)$row['migration_name'];
            if (preg_match('/^[0-9]{14}$/D', $version) !== 1
                || preg_match('/^[a-z][a-z0-9_]*$/D', $name) !== 1 || isset($versions[$version])) {
                throw new MigrationException('Invalid or duplicate migration identity in history table ' . $this->tableName . '.');
            }
            $versions[$version] = $name;
        }
        return $versions;
    }

    /** Fail before migration SQL; never alter a pre-existing table to make it fit. */
    private function assertCompatibleSchema(): void
    {
        $columns = $this->connection->fetchAll(
            'SELECT c.COLUMN_NAME, c.COLUMN_TYPE, c.IS_NULLABLE, c.EXTRA, c.CHARACTER_SET_NAME, t.ENGINE '
            . 'FROM information_schema.columns c JOIN information_schema.tables t '
            . 'ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME '
            . 'WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = ?', [$this->tableName]
        );
        $expected = ['version' => 'varchar(14)', 'migration_name' => 'varchar(255)',
            'executed_at' => 'datetime', 'execution_time_ms' => 'int unsigned'];
        $valid = count($columns) === count($expected);
        foreach ($columns as $column) {
            $name = $column['COLUMN_NAME'];
            $type = preg_replace('/^int\([0-9]+\)/', 'int', strtolower($column['COLUMN_TYPE']));
            $valid = $valid && isset($expected[$name]) && $type === $expected[$name]
                && $column['IS_NULLABLE'] === 'NO' && $column['EXTRA'] === ''
                && $column['ENGINE'] === 'InnoDB'
                && (!in_array($name, ['version', 'migration_name'], true)
                    || $column['CHARACTER_SET_NAME'] === 'utf8mb4');
        }
        $keys = $this->connection->fetchAll(
            'SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX, SUB_PART FROM information_schema.statistics '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0', [$this->tableName]
        );
        $valid = $valid && count($keys) === 1 && $keys[0]['INDEX_NAME'] === 'PRIMARY'
            && $keys[0]['COLUMN_NAME'] === 'version' && (int)$keys[0]['SEQ_IN_INDEX'] === 1
            && $keys[0]['SUB_PART'] === null;
        if (!$valid) {
            throw new MigrationException('Incompatible migration history table ' . $this->tableName
                . '. Use a separate table_name or repair its schema before running migrations.');
        }
    }

    public function record(MigrationFile $file, int $executionTimeMs): void
    {
        $this->connection->executePrepared(
            'INSERT INTO `' . $this->tableName . '` '
            . '(`version`, `migration_name`, `executed_at`, `execution_time_ms`) '
            . 'VALUES (?, ?, NOW(), ?)',
            [$file->getVersion(), $file->getName(), $executionTimeMs]
        );
    }

    public function remove(string $version): void
    {
        $this->connection->executePrepared(
            'DELETE FROM `' . $this->tableName . '` WHERE `version` = ?', [$version]
        );
    }
}
