<?php

namespace Kgkg\MigrationManager;

final class ConsoleApplication
{
    /**
     * @param string[] $arguments
     */
    public function run(array $arguments): int
    {
        try {
            return $this->dispatch($arguments);
        } catch (\Throwable $error) {
            fwrite(STDERR, 'Migration error: ' . $error->getMessage() . "\n");
            return 1;
        }
    }

    private function dispatch(array $arguments): int
    {
        $positionals = [];
        $config = null;
        $steps = null;
        $help = false;
        for ($i = 1, $count = count($arguments); $i < $count; $i++) {
            $argument = $arguments[$i];
            if ($argument === '--help') {
                $help = true;
            } elseif ($argument === '--config' || strpos($argument, '--config=') === 0) {
                if ($config !== null) {
                    throw new MigrationException('The --config option may only be specified once.');
                }
                $config = $argument === '--config' ? ($arguments[++$i] ?? '') : substr($argument, 9);
                if (trim($config) === '' || $config[0] === '-') {
                    throw new MigrationException('The --config option requires a file path.');
                }
            } elseif ($argument === '--steps' || strpos($argument, '--steps=') === 0) {
                if ($steps !== null) {
                    throw new MigrationException('The --steps option may only be specified once.');
                }
                $value = $argument === '--steps' ? ($arguments[++$i] ?? '') : substr($argument, 8);
                if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1
                    || filter_var($value, FILTER_VALIDATE_INT) === false) {
                    throw new MigrationException('The --steps option requires a positive integer within the PHP integer range.');
                }
                $steps = (int)$value;
            } elseif (isset($argument[0]) && $argument[0] === '-') {
                throw new MigrationException('Unknown option: ' . $argument);
            } else {
                $positionals[] = $argument;
            }
        }
        $command = $positionals[0] ?? '';
        if ($command !== '' && !in_array($command, ['init', 'create', 'show', 'run', 'rollback'], true)) {
            throw new MigrationException('Unknown command: ' . $command);
        }
        if (count($positionals) > ($command === 'create' ? 2 : 1)) {
            throw new MigrationException('Unexpected positional argument. Quote names containing spaces.');
        }
        if ($steps !== null && $command !== 'rollback') {
            throw new MigrationException('The --steps option is only valid for rollback.');
        }
        if ($help) {
            fwrite(STDOUT, "Usage: migration-manager <command> [options]\n"
                . "Commands: init, create <name>, show, run, rollback [--steps=1]\n"
                . "Options: --help, --config <file>, --config=<file>\n"
                . "Default configuration: migration.config.php (relative to CWD).\n"
                . "init creates configuration and db/migrations without overwriting files.\n"
                . "create accepts one name; quote names containing spaces.\n"
                . "rollback reverts migrations in descending version order; steps defaults to 1.\n"
                . "Without a name, create prompts only when STDIN is a terminal.\n");
            return 0;
        }
        if ($command === 'init') {
            return $this->initialize($config ?? 'migration.config.php');
        }

        if ($command === 'create') {
            return $this->createMigration($positionals[1] ?? null, $config ?? 'migration.config.php');
        }

        if (in_array($command, ['show', 'run', 'rollback'], true)) {
            $configuration = Configuration::load($config ?? 'migration.config.php');
            $manager = new MigrationManager($configuration->getConnection(), $configuration->getMigrationsPath(),
                $configuration->getTableName(), $configuration->getLockTimeout());
            return $command === 'show' ? $this->showPendingMigrations($manager)
                : $this->executeMigrations($manager, $command === 'rollback', $steps ?? 1);
        }

        throw new MigrationException('A command is required. Use --help for usage.');
    }

    private function createMigration(?string $name, string $config): int
    {
        if ($name === null) {
            if (!stream_isatty(STDIN)) {
                throw new MigrationException('A migration name is required in non-interactive mode.');
            }
            fwrite(STDOUT, 'Migration name: ');
            $input = fgets(STDIN);
            if ($input === false) {
                throw new MigrationException('Unable to read the migration name.');
            }
            $name = trim($input);
        }

        $configuration = Configuration::load($config);
        $path = (new MigrationCreator($configuration->getMigrationsPath()))->create($name);
        fwrite(STDOUT, "Created migration: {$path}\n");

        return 0;
    }

    private function initialize(string $filename): int
    {
        if (strpos($filename, "\0") !== false || strpos($filename, '://') !== false
            || preg_match('~^[A-Za-z]:(?![/\\\\])~', $filename)
            || ($filename[0] === '\\' && substr($filename, 0, 2) !== '\\\\')) {
            throw new MigrationException('The configuration target must be an absolute or relative filesystem path.');
        }
        $directory = realpath(dirname($filename));
        if ($directory === false || !is_dir($directory)) {
            throw new MigrationException('The configuration parent directory must exist.');
        }
        $path = $directory . DIRECTORY_SEPARATOR . basename($filename);
        if (file_exists($path) || is_link($path)) {
            throw new MigrationException('Refusing to overwrite existing configuration: ' . $path);
        }
        $migrations = $directory . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'migrations';
        if (!is_dir($migrations) && !@mkdir($migrations, 0775, true) && !is_dir($migrations)) {
            throw new MigrationException('Unable to create the migration directory: ' . $migrations);
        }
        $contents = file_get_contents(dirname(__DIR__) . '/examples/migration.config.php');
        if ($contents === false) {
            throw new MigrationException('Unable to read the example configuration.');
        }
        // Exclusive creation protects existing files against concurrent init calls.
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new MigrationException('Unable to create configuration without overwriting: ' . $path);
        }
        try {
            if (fwrite($handle, $contents) !== strlen($contents)) {
                throw new MigrationException('Unable to write configuration: ' . $path);
            }
        } finally {
            fclose($handle);
        }
        fwrite(STDOUT, "Created configuration: {$path}\nMigration directory: {$migrations}\n");
        return 0;
    }

    private function executeMigrations(MigrationManager $manager, bool $rollback, int $steps): int
    {
        $startedAt = microtime(true);
        $verb = $rollback ? 'Rolled back' : 'Executed';
        $callback = static function (MigrationFile $file, int $executionTimeMs) use ($verb): void {
            fwrite(STDOUT, "{$verb} {$file->getVersion()}_{$file->getName()} ({$executionTimeMs} ms)\n");
        };
        $executed = $rollback ? $manager->rollback($steps, $callback) : $manager->runPending($callback);

        if ($executed === []) {
            fwrite(STDOUT, $rollback ? "No migrations to roll back.\n" : "No pending migrations.\n");
        }
        $elapsed = max(0, (int)round((microtime(true) - $startedAt) * 1000));
        fwrite(STDOUT, $verb . ' migrations: ' . count($executed) . " ({$elapsed} ms total)\n");

        return 0;
    }

    private function showPendingMigrations(MigrationManager $manager): int
    {
        $pending = $manager->getPending();

        if ($pending === []) {
            fwrite(STDOUT, "No pending migrations.\n");

            return 0;
        }

        fwrite(STDOUT, "Pending migrations:\n");
        foreach ($pending as $file) {
            fwrite(STDOUT, "  {$file->getVersion()}_{$file->getName()}\n");
        }
        fwrite(STDOUT, 'Total: ' . count($pending) . "\n");

        return 0;
    }
}
