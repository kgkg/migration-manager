<?php

namespace Kgkg\MigrationManager\Connection;

/**
 * MySQL operations on one session. Implementations throw ConnectionException
 * on database errors and never expose driver result objects.
 */
interface ConnectionInterface
{
    /** Execute zero or more SQL statements, consuming and freeing all results. */
    public function execute(string $sql): void;

    /**
     * Execute one statement with positional ? placeholders; discard its result.
     *
     * @param list<null|bool|int|float|string> $parameters
     */
    public function executePrepared(string $sql, array $parameters = []): void;

    /**
     * Return the first column of the first row, or null for no row / SQL NULL.
     *
     * @param list<null|bool|int|float|string> $parameters
     * @return null|int|float|string
     */
    public function fetchValue(string $sql, array $parameters = []);

    /**
     * Return associative rows, or an empty list when no rows match.
     *
     * @param list<null|bool|int|float|string> $parameters
     * @return list<array<string, null|int|float|string>>
     */
    public function fetchAll(string $sql, array $parameters = []): array;

    /** Look up literal table and column names in the current database. */
    public function hasColumn(string $tableName, string $columnName): bool;

    /** Return the selected database name; throw if none is selected. */
    public function getDatabaseName(): string;
}
