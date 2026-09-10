<?php

namespace Kgkg\MigrationManager\Tests\Unit;

final class ConsoleApplicationTest extends MigrationManagerTestCase
{
    use \Kgkg\MigrationManager\Tests\Support\ConsoleProcess;

    public function testHelpDoesNotLoadConfiguration(): void
    {
        foreach ([['--help'], ['create', '--config=missing.php', '--help'],
            ['rollback', '--steps=2', '--config=missing.php', '--help']] as $arguments) {
            [$code, $output, $error] = $this->cli($arguments);
            $this->assertSame(0, $code, $error);
            $this->assertStringContainsString('Usage: migration-manager', $output);
            $this->assertSame('', $error);
        }
    }

    public function testInitAndCreateWorkWithUnconfiguredDatabase(): void
    {
        [$code, , $error] = $this->cli(['init']);
        $this->assertSame(0, $code, $error);
        $config = $this->temporaryDirectory . '/migration.config.php';
        $this->assertSame(file_get_contents(dirname(__DIR__, 2) . '/examples/migration.config.php'), file_get_contents($config));
        $this->assertDirectoryExists($this->temporaryDirectory . '/db/migrations');
        [$code, $output, $error] = $this->cli(['create', 'Create User Profiles']);
        $this->assertSame(0, $code, $error);
        $this->assertStringContainsString('Created migration:', $output);
        $files = glob($this->temporaryDirectory . '/db/migrations/*_create_user_profiles.php');
        $this->assertCount(1, $files);
        $this->assertStringContainsString('use Kgkg\MigrationManager\AbstractMigration;', file_get_contents($files[0]));
        $original = file_get_contents($files[0]);
        [$code, , $error] = $this->cli(['create', 'create-user-profiles']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('already exists', $error);
        $this->assertSame($original, file_get_contents($files[0]));
        [$code, , $error] = $this->cli(['init']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Refusing to overwrite', $error);
        $this->assertSame(file_get_contents(dirname(__DIR__, 2) . '/examples/migration.config.php'), file_get_contents($config));
        $this->assertSame($original, file_get_contents($files[0]));
    }

    public function testAlternateConfigurationDoesNotInvokeBootstrapOrFactory(): void
    {
        mkdir($this->temporaryDirectory . '/settings with spaces');
        file_put_contents($this->temporaryDirectory . '/settings with spaces/bootstrap.php', '<?php throw new RuntimeException("Bootstrap invoked");');
        file_put_contents($this->temporaryDirectory . '/settings with spaces/custom.php', <<<'PHP'
<?php
return [
    'migrations_path' => 'custom migrations',
    'bootstrap' => 'bootstrap.php',
    'connection' => static function () { throw new RuntimeException('Factory invoked'); },
];
PHP
        );
        foreach ([['--config', 'settings with spaces/custom.php', 'create', 'First'],
            ['create', 'Second', '--config=' . $this->temporaryDirectory . '/settings with spaces/custom.php']] as $arguments) {
            [$code, , $error] = $this->cli($arguments);
            $this->assertSame(0, $code, $error);
            $this->assertSame('', $error);
        }
        $this->assertCount(2, glob($this->temporaryDirectory . '/settings with spaces/custom migrations/*.php'));
        $this->assertDirectoryDoesNotExist($this->temporaryDirectory . '/db');
    }

    public function testInitUsesConfigurationDirectoryAndPreservesExistingMigrations(): void
    {
        mkdir($this->temporaryDirectory . '/settings/db/migrations', 0777, true);
        $existing = $this->temporaryDirectory . '/settings/db/migrations/existing.php';
        file_put_contents($existing, 'preserve me');
        [$code, , $error] = $this->cli(['init', '--config=settings/custom.php']);
        $this->assertSame(0, $code, $error);
        $this->assertFileExists($this->temporaryDirectory . '/settings/custom.php');
        $this->assertSame('preserve me', file_get_contents($existing));
        $this->assertDirectoryDoesNotExist($this->temporaryDirectory . '/db');
    }

    /** @dataProvider invalidArguments */
    public function testInvalidArgumentsFailWithoutSideEffects(array $arguments, string $message): void
    {
        [$code, $output, $error] = $this->cli($arguments);
        $this->assertSame(1, $code);
        $this->assertSame('', $output);
        $this->assertStringContainsString($message, $error);
        $this->assertSame([], glob($this->temporaryDirectory . '/*'));
    }

    public function invalidArguments(): array
    {
        return [
            [[], 'A command is required'],
            [['unknown'], 'Unknown command'],
            [['init', '--unknown'], 'Unknown option'],
            [['--help', '--unknown'], 'Unknown option'],
            [['init', 'extra'], 'Unexpected positional'],
            [['create', 'one', 'two'], 'Unexpected positional'],
            [['create'], 'required in non-interactive mode'],
            [['init', '--config'], 'requires a file path'],
            [['init', '--config='], 'requires a file path'],
            [['init', '--config', '--help'], 'requires a file path'],
            [['init', '--config=a', '--config=b'], 'only be specified once'],
            [['create', 'Example'], 'Missing or unreadable configuration'],
            [['init', '--config=missing/config.php'], 'parent directory must exist'],
            [['init', '--config=php://memory'], 'filesystem path'],
            [['init', '--config=C:config.php'], 'filesystem path'],
            [['rollback', '--steps=0'], 'positive integer'],
            [['rollback', '--steps=-1'], 'positive integer'],
            [['rollback', '--steps=1.5'], 'positive integer'],
            [['rollback', '--steps=1e2'], 'positive integer'],
            [['rollback', '--steps=abc'], 'positive integer'],
            [['rollback', '--steps=999999999999999999999999'], 'positive integer'],
            [['rollback', '--steps='], 'positive integer'],
            [['rollback', '--steps'], 'positive integer'],
            [['rollback', '--steps=1', '--steps=2'], 'only be specified once'],
            [['run', '--steps=1'], 'only valid for rollback'],
            [['rollback', 'extra'], 'Unexpected positional'],
            [['show'], 'Missing or unreadable configuration'],
            [['run'], 'Missing or unreadable configuration'],
            [['rollback'], 'Missing or unreadable configuration'],
        ];
    }

    public function testConfigurationAndGeneratorErrorsGoToStderr(): void
    {
        file_put_contents($this->temporaryDirectory . '/migration.config.php', '<?php return false;');
        [$code, $output, $error] = $this->cli(['create', 'Example']);
        $this->assertSame(1, $code);
        $this->assertSame('', $output);
        $this->assertStringContainsString('must return an array', $error);
        copy(dirname(__DIR__, 2) . '/examples/migration.config.php', $this->temporaryDirectory . '/migration.config.php');
        [$code, $output, $error] = $this->cli(['create', '123']);
        $this->assertSame(1, $code);
        $this->assertSame('', $output);
        $this->assertStringContainsString('must start with a letter', $error);
    }

    public function testInitReportsDirectoryConflictWithoutWritingConfiguration(): void
    {
        file_put_contents($this->temporaryDirectory . '/db', 'preserve me');
        [$code, $output, $error] = $this->cli(['init']);
        $this->assertSame(1, $code);
        $this->assertSame('', $output);
        $this->assertStringContainsString('Unable to create the migration directory', $error);
        $this->assertFileDoesNotExist($this->temporaryDirectory . '/migration.config.php');
        $this->assertSame('preserve me', file_get_contents($this->temporaryDirectory . '/db'));
    }

    public function testEntrypointUsesProxyAutoloaderOutsidePackageDirectory(): void
    {
        $entrypoint = $this->temporaryDirectory . '/installed-bin';
        copy(dirname(__DIR__, 2) . '/bin/migration-manager', $entrypoint);
        $proxy = $this->temporaryDirectory . '/proxy.php';
        file_put_contents($proxy, '<?php $_composer_autoload_path = '
            . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true)
            . '; require ' . var_export($entrypoint, true) . ';');
        [$code, $output, $error] = $this->cli(['--help'], $proxy);
        $this->assertSame(0, $code, $error);
        $this->assertStringContainsString('Usage: migration-manager', $output);
        $this->assertSame('', $error);
        [$code, $output, $error] = $this->cli(['--help'], $entrypoint);
        $this->assertSame(1, $code);
        $this->assertSame('', $output);
        $this->assertStringContainsString('Autoloader not found', $error);
    }

}
