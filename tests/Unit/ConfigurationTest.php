<?php

namespace Kgkg\MigrationManager\Tests\Unit;

use Kgkg\MigrationManager\Configuration;
use Kgkg\MigrationManager\Connection\ConnectionInterface;
use Kgkg\MigrationManager\MigrationCreator;
use Kgkg\MigrationManager\MigrationException;

final class ConfigurationTest extends MigrationManagerTestCase
{
    private function config(string $settings): Configuration
    {
        $path = $this->writeMigrationFile('migration.config.php', '<?php return ' . $settings . ';');
        return Configuration::load($path);
    }

    public function test_loading_and_generation_are_offline_and_do_not_change_cwd(): void
    {
        $cwd = getcwd();
        $config = $this->config('["migrations_path" => "db/migrations", "connection" => static function () {
            throw new \\RuntimeException("The database is unavailable.");
        }]');
        $this->assertSame($this->temporaryDirectory . DIRECTORY_SEPARATOR . 'db/migrations', $config->getMigrationsPath());
        $this->assertSame('schema_migrations', $config->getTableName());
        $this->assertSame(0, $config->getLockTimeout());
        $this->assertDirectoryDoesNotExist($config->getMigrationsPath());
        $file = (new MigrationCreator($config->getMigrationsPath()))->create('offline_example');
        $this->assertFileExists($file);
        $this->assertSame($cwd, getcwd());
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('The database is unavailable.');
        $config->getConnection();
    }

    public function test_default_filename_is_relative_to_cwd(): void
    {
        $this->writeMigrationFile('migration.config.php', '<?php return ["migrations_path" => "db", "connection" => function () {}];');
        $cwd = getcwd();
        try {
            chdir($this->temporaryDirectory);
            $this->assertSame($this->temporaryDirectory . DIRECTORY_SEPARATOR . 'db', Configuration::load()->getMigrationsPath());
            $this->assertSame($this->temporaryDirectory, getcwd());
            $this->assertSame(Configuration::load()->getMigrationsPath(), Configuration::load('./migration.config.php')->getMigrationsPath());
        } finally {
            chdir($cwd);
        }
    }

    /** @dataProvider paths */
    public function test_absolute_and_relative_paths(string $path, bool $absolute): void
    {
        $config = $this->config('["migrations_path" => ' . var_export($path, true)
            . ', "connection" => function () {}, "table_name" => "custom_history", "lock_timeout" => 12]');
        $this->assertSame($absolute ? $path : $this->temporaryDirectory . DIRECTORY_SEPARATOR . $path, $config->getMigrationsPath());
        $this->assertSame('custom_history', $config->getTableName());
        $this->assertSame(12, $config->getLockTimeout());
    }

    public function paths(): array
    {
        return [['/var/migrations', true], ['C:\\app\\migrations', true], ['C:/app/migrations', true],
            ['\\\\server\\share\\migrations', true], ['//server/share/migrations', true],
            ['../db/migrations', false], ['folder with spaces/db', false], ['db\\migrations', false]];
    }

    /** @dataProvider invalidSettings */
    public function test_invalid_configuration_is_rejected_without_initialization(string $settings): void
    {
        $this->expectException(MigrationException::class);
        $this->config($settings);
    }

    public function invalidSettings(): array
    {
        $base = ['migrations_path' => 'db', 'connection' => 'strlen'];
        $cases = [['null'], ['false'], ['"text"'], ['[]']];
        foreach ([
            ['migrations_path', null], ['migrations_path', ''], ['migrations_path', 1],
            ['migrations_path', "db\0bad"], ['migrations_path', 'php://memory'],
            ['migrations_path', 'C:db'], ['migrations_path', '\\db'],
            ['connection', null], ['connection', 'no_such_factory'], ['connection', 1],
            ['table_name', null], ['table_name', ''], ['table_name', 'db.table'],
            ['table_name', "table\n"], ['table_name', str_repeat('a', 65)], ['table_name', 1],
            ['lock_timeout', null], ['lock_timeout', -1], ['lock_timeout', '1'],
            ['lock_timeout', 1.5], ['lock_timeout', true],
            ['bootstrap', 'missing.php'], ['bootstrap', ''], ['bootstrap', false],
            ['unknown', true],
        ] as [$key, $value]) {
            $cases[] = [var_export(array_replace($base, [$key => $value]), true)];
        }
        return $cases;
    }

    public function test_missing_file_and_directory_are_rejected(): void
    {
        foreach ([$this->temporaryDirectory . '/absent.php', $this->temporaryDirectory] as $path) {
            try {
                Configuration::load($path);
                $this->fail('An unreadable configuration must fail.');
            } catch (MigrationException $error) {
                $this->assertStringContainsString('Missing or unreadable configuration file', $error->getMessage());
            }
        }
    }

    /** @dataProvider brokenFiles */
    public function test_file_errors_preserve_the_original_exception(string $source, string $type): void
    {
        $path = $this->writeMigrationFile('broken.php', $source);
        try {
            Configuration::load($path);
            $this->fail('The configuration must fail.');
        } catch (MigrationException $error) {
            $this->assertInstanceOf($type, $error->getPrevious());
            $this->assertStringContainsString('Unable to load configuration file', $error->getMessage());
        }
    }

    public function brokenFiles(): array
    {
        return [['<?php throw new \\RuntimeException("Config failed");', \RuntimeException::class],
            ['<?php return [;', \ParseError::class]];
    }

    public function test_bootstrap_precedes_factory_and_connection_is_cached(): void
    {
        $key = 'configuration_test_' . bin2hex(random_bytes(8));
        $keyLiteral = var_export($key, true);
        $db = $this->createMock(ConnectionInterface::class);
        $GLOBALS[$key] = ['events' => [], 'db' => $db];
        $this->writeMigrationFile('bootstrap.php', '<?php $GLOBALS[' . $keyLiteral . ']["events"][] = "bootstrap";');
        try {
            $config = $this->config('["migrations_path" => "db", "bootstrap" => "bootstrap.php", "connection" => function () {
                $GLOBALS[' . $keyLiteral . ']["events"][] = "factory";
                return $GLOBALS[' . $keyLiteral . ']["db"];
            }]');
            $this->assertSame([], $GLOBALS[$key]['events']);
            $this->assertSame($db, $config->getConnection());
            $this->assertSame($db, $config->getConnection());
            $this->assertSame(['bootstrap', 'factory'], $GLOBALS[$key]['events']);
            // Another configuration shares require_once bootstrap semantics, but owns its factory cache.
            $this->assertSame($db, Configuration::load($this->temporaryDirectory . '/migration.config.php')->getConnection());
            $this->assertSame(['bootstrap', 'factory', 'factory'], $GLOBALS[$key]['events']);
        } finally {
            unset($GLOBALS[$key]);
        }
    }

    /** @dataProvider initializationFailures */
    public function test_initialization_errors_are_retained(string $bootstrap, string $factory, ?string $previous): void
    {
        $this->writeMigrationFile('bootstrap.php', '<?php ' . $bootstrap);
        $config = $this->config('["migrations_path" => "db", "bootstrap" => "bootstrap.php", "connection" => function () {' . $factory . '}]');
        $first = null;
        for ($i = 0; $i < 2; $i++) {
            try {
                $config->getConnection();
                $this->fail('Initialization must fail.');
            } catch (MigrationException $error) {
                if ($i === 0) {
                    $first = $error;
                    if ($previous !== null) {
                        $this->assertInstanceOf($previous, $error->getPrevious());
                    }
                } else {
                    $this->assertSame($first, $error);
                }
            }
        }
    }

    public function initializationFailures(): array
    {
        return [
            ['throw new \\RuntimeException("Bootstrap failed");', 'throw new \\LogicException("Must not run");', \RuntimeException::class],
            ['', 'throw new \\RuntimeException("Factory failed");', \RuntimeException::class],
            ['', 'return null;', null], ['', 'return new \\stdClass();', null],
        ];
    }

    public function test_example_loads_without_environment_or_database_access(): void
    {
        $config = Configuration::load(dirname(__DIR__, 2) . '/examples/migration.config.php');
        $this->assertSame('schema_migrations', $config->getTableName());
        $this->assertSame(0, $config->getLockTimeout());
    }

    public function test_bootstrap_removed_after_loading_prevents_factory_execution(): void
    {
        $path = $this->writeMigrationFile('bootstrap.php');
        $config = $this->config('["migrations_path" => "db", "bootstrap" => "bootstrap.php",
            "connection" => function () { throw new \\LogicException("Factory must not run"); }]');
        unlink($path);
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('Missing or unreadable bootstrap file');
        $config->getConnection();
    }

    public function test_recursive_factory_is_rejected(): void
    {
        $key = 'recursive_configuration_' . bin2hex(random_bytes(8));
        $config = $this->config('["migrations_path" => "db", "connection" => function () {
            return $GLOBALS[' . var_export($key, true) . ']->getConnection();
        }]');
        $GLOBALS[$key] = $config;
        try {
            $this->expectException(MigrationException::class);
            $this->expectExceptionMessage('Recursive connection initialization');
            $config->getConnection();
        } finally {
            unset($GLOBALS[$key]);
        }
    }
}
