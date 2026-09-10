<?php

namespace Kgkg\MigrationManager;

use Kgkg\MigrationManager\Connection\ConnectionInterface;
use Throwable;

/** Validated file configuration with lazy application bootstrap and connection. */
final class Configuration
{
    private string $migrationsPath;
    private string $tableName;
    private int $lockTimeout;
    private ?string $bootstrap;
    /** @var callable */
    private $factory;
    private ?ConnectionInterface $connection = null;
    private ?Throwable $failure = null;
    private bool $initializing = false;

    private function __construct(array $settings, string $directory)
    {
        if (array_diff(array_keys($settings), ['migrations_path', 'connection', 'table_name', 'lock_timeout', 'bootstrap'])) {
            throw new MigrationException('Unknown migration configuration option.');
        }
        $this->migrationsPath = self::resolvePath($settings['migrations_path'] ?? null, $directory, 'migrations_path');
        if (!isset($settings['connection']) || !is_callable($settings['connection'])) {
            throw new MigrationException('Configuration connection must be a callable factory.');
        }
        $this->factory = $settings['connection'];
        $table = array_key_exists('table_name', $settings) ? $settings['table_name'] : 'schema_migrations';
        if (!is_string($table) || strlen($table) > 64 || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $table) !== 1) {
            throw new MigrationException('Configuration table_name must be a valid SQL identifier of at most 64 characters.');
        }
        $timeout = array_key_exists('lock_timeout', $settings) ? $settings['lock_timeout'] : 0;
        if (!is_int($timeout) || $timeout < 0) {
            throw new MigrationException('Configuration lock_timeout must be a nonnegative integer.');
        }
        $this->tableName = $table;
        $this->lockTimeout = $timeout;
        $this->bootstrap = isset($settings['bootstrap'])
            ? self::resolvePath($settings['bootstrap'], $directory, 'bootstrap') : null;
        if ($this->bootstrap !== null) {
            self::assertReadableFile($this->bootstrap, 'bootstrap');
        }
    }

    /** Relative config filenames are resolved from CWD; contained paths from the config directory. */
    public static function load(string $filename = 'migration.config.php'): self
    {
        $directory = getcwd();
        if ($directory === false) {
            throw new MigrationException('Unable to determine the current working directory.');
        }
        $path = self::resolvePath($filename, $directory, 'configuration');
        self::assertReadableFile($path, 'configuration');
        $canonical = realpath($path);
        if ($canonical === false) {
            throw new MigrationException('Unable to resolve configuration file: ' . $path);
        }
        try {
            $settings = self::readFile($canonical);
        } catch (Throwable $error) {
            throw new MigrationException('Unable to load configuration file: ' . $canonical, 0, $error);
        }
        if (!is_array($settings)) {
            throw new MigrationException('The migration configuration file must return an array.');
        }
        return new self($settings, dirname($canonical));
    }

    public function getMigrationsPath(): string
    {
        return $this->migrationsPath;
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    public function getLockTimeout(): int
    {
        return $this->lockTimeout;
    }

    /** Bootstrap and factory run only on first connection access; failures are retained. */
    public function getConnection(): ConnectionInterface
    {
        if ($this->connection !== null) {
            return $this->connection;
        }
        if ($this->failure !== null) {
            throw $this->failure;
        }
        if ($this->initializing) {
            throw new MigrationException('Recursive connection initialization is not allowed.');
        }
        $this->initializing = true;
        try {
            if ($this->bootstrap !== null) {
                self::assertReadableFile($this->bootstrap, 'bootstrap');
                self::bootstrapFile($this->bootstrap);
            }
            $connection = ($this->factory)();
            if (!$connection instanceof ConnectionInterface) {
                throw new MigrationException('The connection factory must return ConnectionInterface.');
            }
            $this->connection = $connection;
            return $connection;
        } catch (Throwable $error) {
            $this->failure = $error instanceof MigrationException ? $error
                : new MigrationException('Unable to initialize the migration connection: ' . $error->getMessage(), 0, $error);
            throw $this->failure;
        } finally {
            $this->initializing = false;
        }
    }

    private static function readFile(string $path)
    {
        return require $path;
    }

    private static function bootstrapFile(string $path): void
    {
        require_once $path;
    }

    private static function assertReadableFile(string $path, string $option): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new MigrationException('Missing or unreadable ' . $option . ' file: ' . $path);
        }
    }

    private static function resolvePath($path, string $directory, string $option): string
    {
        if (!is_string($path) || trim($path) === '' || strpos($path, "\0") !== false) {
            throw new MigrationException('Configuration ' . $option . ' must be a nonempty filesystem path.');
        }
        // Reject stream wrappers and drive-relative paths, whose meaning depends on hidden process state.
        if (preg_match('~^[A-Za-z][A-Za-z0-9+.-]*://~', $path)
            || preg_match('~^[A-Za-z]:(?![/\\\\])~', $path)) {
            throw new MigrationException('Configuration ' . $option . ' must use an absolute or relative filesystem path.');
        }
        if ($path[0] === '/' || substr($path, 0, 2) === '\\\\'
            || preg_match('~^[A-Za-z]:[/\\\\]~', $path)) {
            return $path;
        }
        if ($path[0] === '\\') {
            throw new MigrationException('Configuration ' . $option . ' must not use a drive-relative root.');
        }
        return $directory . DIRECTORY_SEPARATOR . $path;
    }
}
