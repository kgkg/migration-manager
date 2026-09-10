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
        $versions = [];
        foreach ($this->connection->fetchAll(
            'SELECT `version` FROM `' . $this->tableName . '` ORDER BY `version` ASC'
        ) as $row) {
            $versions[(string)$row['version']] = true;
        }
        return $versions;
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
