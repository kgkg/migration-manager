<?php

namespace Kgkg\MigrationManager;

use Kgkg\MigrationManager\Connection\ConnectionInterface;

abstract class AbstractMigration
{
    private ConnectionInterface $database;

    final public function __construct(ConnectionInterface $database)
    {
        $this->database = $database;
    }

    abstract public function up(): void;

    abstract public function down(): void;

    /**
     * Executes one or more SQL statements.
     */
    final protected function execute(string $sql): void
    {
        $this->database->execute($sql);
    }

    final protected function getDatabase(): ConnectionInterface
    {
        return $this->database;
    }

    protected function hasColumn(string $tableName, string $columnName): bool
    {
        return $this->database->hasColumn($tableName, $columnName);
    }

}
