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

            foreach ($this->findPending($this->history->getAppliedMigrations()) as $file) {
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
            ? $this->history->getAppliedMigrations()
            : [];

        return $this->findPending($appliedVersions);
    }

    /**
     * Rolls back migrations in descending version order, not execution order.
     * Stops on the first failure; previously completed steps remain rolled back.
     *
     * @param callable|null $afterMigration function (MigrationFile $file, int $executionTimeMs): void
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

            $applied = $this->history->getAppliedMigrations();
            $versions = array_reverse(array_keys($applied));
            $versions = array_slice($versions, 0, $steps);
            $rolledBack = [];

            // Validate every selected identity before any down() can mutate data.
            foreach ($versions as $version) {
                $version = (string)$version;
                if (isset($filesByVersion[$version])) {
                    $this->assertIdentity($filesByVersion[$version], $applied);
                }
            }

            foreach ($versions as $version) {
                $version = (string)$version;
                if (!isset($filesByVersion[$version])) {
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
     * @param array<string, string> $appliedVersions
     * @return MigrationFile[]
     */
    private function findPending(array $appliedVersions): array
    {
        $files = $this->repository->findAll();
        foreach ($files as $file) {
            $this->assertIdentity($file, $appliedVersions);
        }
        return array_values(
            array_filter(
                $files,
                static function (MigrationFile $file) use ($appliedVersions): bool {
                    return isset($appliedVersions[$file->getVersion()]) === false;
                }
            )
        );
    }

    private function assertIdentity(MigrationFile $file, array $applied): void
    {
        if (isset($applied[$file->getVersion()]) && $applied[$file->getVersion()] !== $file->getName()) {
            throw new MigrationException('Migration identity mismatch for version ' . $file->getVersion()
                . ': history records ' . $applied[$file->getVersion()] . ', file is ' . $file->getName() . '.');
        }
    }

    /** Run history and migration work while holding this session's advisory lock. */
    private function withLock(callable $operation): array
    {
        if ($this->acquiredLockName !== null) {
            throw new MigrationException('This manager is already running migrations.');
        }
        $this->database->assertMigrationSession();
        $lockName = $this->database->getMigrationLockName($this->tableName);
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
