<?php

namespace Kgkg\MigrationManager;

use Kgkg\MigrationManager\Connection\ConnectionInterface;
use ReflectionClass;

final class MigrationRepository
{
    private string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/\\');
    }

    /**
     * @return MigrationFile[]
     */
    public function findAll(): array
    {
        if (is_dir($this->directory) === false) {
            throw new MigrationException("Migration directory does not exist: {$this->directory}");
        }

        $paths = glob($this->directory . DIRECTORY_SEPARATOR . '*.php');
        if ($paths === false) {
            throw new MigrationException("Unable to read the migration directory: {$this->directory}");
        }

        $migrations = [];
        foreach ($paths as $path) {
            $fileName = basename($path);
            if (preg_match('/^(\d{14})_([a-z][a-z0-9_]*)\.php$/', $fileName, $matches) !== 1) {
                throw new MigrationException(
                    "Invalid migration filename '{$fileName}'. "
                    . 'Expected format: YYYYMMDDHHMMSS_migration_name.php'
                );
            }

            $version = $matches[1];
            $name = $matches[2];
            if (isset($migrations[$version])) {
                throw new MigrationException("More than one migration has version {$version}.");
            }

            $migrations[$version] = new MigrationFile(
                $version,
                $name,
                $this->classNameFromMigrationName($name),
                $path
            );
        }

        ksort($migrations, SORT_STRING);

        return array_values($migrations);
    }

    public function load(MigrationFile $file, ConnectionInterface $database): AbstractMigration
    {
        $path = realpath($file->getPath());
        $className = $file->getClassName();

        if ($path === false || !is_file($path) || !is_readable($path)) {
            throw new MigrationException("Unable to read migration file: {$file->getPath()}");
        }

        if (!class_exists($className, false) && !interface_exists($className, false)
            && !trait_exists($className, false)) {
            require_once $path;
        }

        if (class_exists($className, false) === false) {
            throw new MigrationException(
                "File {$path} must declare class {$className}."
            );
        }

        $reflection = new ReflectionClass($className);
        $declaredPath = $reflection->getFileName();
        if ($declaredPath === false || realpath($declaredPath) !== $path) {
            throw new MigrationException(
                "Migration class {$className} is already declared by another file."
            );
        }

        if (is_subclass_of($className, AbstractMigration::class) === false) {
            throw new MigrationException(
                "Class {$className} must extend " . AbstractMigration::class . '.'
            );
        }

        if (!$reflection->isInstantiable()) {
            throw new MigrationException("Migration class {$className} must be instantiable.");
        }

        return new $className($database);
    }

    private function classNameFromMigrationName(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
    }
}
