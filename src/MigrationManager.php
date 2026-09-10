<?php

namespace Kgkg\MigrationManager;

use Kgkg\MigrationManager\Connection\ConnectionInterface;
use Throwable;

final class MigrationManager
{
    private string $tableName;
    private int $lockTimeout;
    private MigrationHistory $history;

    private ConnectionInterface $database;
    private MigrationRepository $repository;
    private ?string $acquiredLockName = null;

    public function __construct(
        ConnectionInterface $database,
        string $migrationsDirectory,
        string $tableName = 'schema_migrations',
        int $lockTimeout = 0
    ) {
        if ($lockTimeout < 0) {
            throw new MigrationException('The lock timeout must be nonnegative.');
        }
        $this->history = new MigrationHistory($database, $tableName);
        $this->tableName = $tableName;
        $this->lockTimeout = $lockTimeout;
        $this->database = $database;
        $this->repository = new MigrationRepository($migrationsDirectory);
    }

    /**
     * @param callable|null $afterMigration function (MigrationFile $file, int $executionTimeMs): void
     * @return MigrationFile[]
     */
    public function runPending(?callable $afterMigration = null): array
    {
        return $this->withLock(function () use ($afterMigration): array {
            $this->history->ensureExists();
            $executed = [];

            foreach ($this->findPending($this->history->getAppliedVersions()) as $file) {
                $startedAt = microtime(true);
                $migration = $this->repository->load($file, $this->database);
                $migration->up();
                $executionTimeMs = max(0, (int)round((microtime(true) - $startedAt) * 1000));

                $this->history->record($file, $executionTimeMs);

                $executed[] = $file;
                if ($afterMigration !== null) {
                    $afterMigration($file, $executionTimeMs);
                }
            }

            return $executed;
        });
    }

    /**
     * Returns pending migrations without creating the history table or acquiring a database lock.
     *
     * @return MigrationFile[]
     */
    public function getPending(): array
    {
        $appliedVersions = $this->history->exists()
            ? $this->history->getAppliedVersions()
            : [];

        return $this->findPending($appliedVersions);
    }

    /**
     * Rolls back migrations in descending version order. Available through the API
     * using the existing history schema.
     *
     * @return MigrationFile[]
     */
    public function rollback(int $steps = 1, ?callable $afterMigration = null): array
    {
        if ($steps < 1) {
            throw new MigrationException('The number of migrations to roll back must be greater than zero.');
        }

        return $this->withLock(function () use ($afterMigration, $steps): array {
            $this->history->ensureExists();
            $filesByVersion = [];
            foreach ($this->repository->findAll() as $file) {
                $filesByVersion[$file->getVersion()] = $file;
            }

            $appliedRows = array_map(static function ($version): array {
                return ['version' => $version];
            }, array_reverse(array_keys($this->history->getAppliedVersions())));
            $appliedRows = array_slice($appliedRows, 0, $steps);
            $rolledBack = [];

            foreach ($appliedRows as $row) {
                $version = (string)$row['version'];
                if (isset($filesByVersion[$version]) === false) {
                    throw new MigrationException("Missing file for applied migration {$version}.");
                }

                $file = $filesByVersion[$version];
                $startedAt = microtime(true);
                $migration = $this->repository->load($file, $this->database);
                $migration->down();
                $executionTimeMs = max(0, (int)round((microtime(true) - $startedAt) * 1000));

                $this->history->remove($version);

                $rolledBack[] = $file;
                if ($afterMigration !== null) {
                    $afterMigration($file, $executionTimeMs);
                }
            }

            return $rolledBack;
        });
    }

    /**
     * @param array<string, bool> $appliedVersions
     * @return MigrationFile[]
     */
    private function findPending(array $appliedVersions): array
    {
        return array_values(
            array_filter(
                $this->repository->findAll(),
                static function (MigrationFile $file) use ($appliedVersions): bool {
                    return isset($appliedVersions[$file->getVersion()]) === false;
                }
            )
        );
    }

    /** Run history and migration work while holding this session's advisory lock. */
    private function withLock(callable $operation): array
    {
        if ($this->acquiredLockName !== null) {
            throw new MigrationException('This manager is already running migrations.');
        }
        $lockName = 'migration_manager_' . sha1($this->database->getDatabaseName() . "\0" . $this->tableName);
        $result = $this->database->fetchValue('SELECT GET_LOCK(?, ?)', [$lockName, $this->lockTimeout]);
        if ((int)$result !== 1) {
            throw new MigrationException($result === null
                ? 'Unable to acquire the migration lock.'
                : 'Another process is currently running migrations.');
        }
        $this->acquiredLockName = $lockName;
        $failure = null;
        try {
            return $operation();
        } catch (Throwable $error) {
            $failure = $error;
            throw $error;
        } finally {
            try {
                $released = $this->database->fetchValue('SELECT RELEASE_LOCK(?)', [$lockName]);
                if ((int)$released !== 1) {
                    throw new MigrationException('Unable to release the migration lock.');
                }
            } catch (Throwable $releaseError) {
                if ($failure === null) {
                    throw $releaseError;
                }
            } finally {
                $this->acquiredLockName = null;
            }
        }
    }
}
