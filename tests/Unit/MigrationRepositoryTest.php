<?php

namespace Kgkg\MigrationManager\Tests\Unit;

use Kgkg\MigrationManager\Connection\ConnectionInterface;
use Kgkg\MigrationManager\AbstractMigration;
use Kgkg\MigrationManager\MigrationException;
use Kgkg\MigrationManager\MigrationRepository;

final class MigrationRepositoryTest extends MigrationManagerTestCase
{
    public function test_repeated_load_uses_a_fresh_instance_and_connection(): void
    {
        $creator = new \Kgkg\MigrationManager\MigrationCreator($this->temporaryDirectory);
        $creator->create('repository repeated connection');
        $repository = new MigrationRepository($this->temporaryDirectory);
        $file = $repository->findAll()[0];
        $firstConnection = $this->createMock(ConnectionInterface::class);
        $secondConnection = $this->createMock(ConnectionInterface::class);
        $first = $repository->load($file, $firstConnection);
        $second = (new MigrationRepository($this->temporaryDirectory . '/.'))->load(
            (new MigrationRepository($this->temporaryDirectory . '/.'))->findAll()[0],
            $secondConnection
        );
        $third = $repository->load($file, $firstConnection);
        $this->assertNotSame($first, $second);
        $this->assertNotSame($first, $third);
        $accessor = new \ReflectionMethod(AbstractMigration::class, 'getDatabase');
        $accessor->setAccessible(true);
        $this->assertSame($firstConnection, $accessor->invoke($first));
        $this->assertSame($secondConnection, $accessor->invoke($second));
        $first->up();
        $this->expectException(\Kgkg\MigrationManager\IrreversibleMigrationException::class);
        $second->down();
    }

    public function test_rejects_class_declared_in_a_different_file(): void
    {
        $creator = new \Kgkg\MigrationManager\MigrationCreator($this->temporaryDirectory);
        $path = $creator->create('repository conflicting class');
        require $path;
        $otherPath = $this->writeMigrationFile('20260714120000_repository_conflicting_class.php',
            (string)file_get_contents($path));
        $file = new \Kgkg\MigrationManager\MigrationFile('20260714120000',
            'repository_conflicting_class', 'RepositoryConflictingClass', $otherPath);
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('already declared by another file');
        (new MigrationRepository($this->temporaryDirectory))->load(
            $file, $this->createMock(ConnectionInterface::class)
        );
    }

    public function test_accepts_a_migration_preloaded_by_the_application(): void
    {
        $path = (new \Kgkg\MigrationManager\MigrationCreator($this->temporaryDirectory))
            ->create('repository preloaded migration');
        require $path;
        $repository = new MigrationRepository($this->temporaryDirectory);
        $this->assertInstanceOf(AbstractMigration::class,
            $repository->load($repository->findAll()[0], $this->createMock(ConnectionInterface::class)));
    }

    public function test_rejects_abstract_migration(): void
    {
        $this->writeMigrationFile('20260714120000_repository_abstract_migration.php',
            '<?php abstract class RepositoryAbstractMigration extends \\Kgkg\\MigrationManager\\AbstractMigration {}');
        $repository = new MigrationRepository($this->temporaryDirectory);
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('must be instantiable');
        $repository->load($repository->findAll()[0], $this->createMock(ConnectionInterface::class));
    }

    public function test_rejects_a_missing_file(): void
    {
        $file = new \Kgkg\MigrationManager\MigrationFile('20260714120000', 'missing',
            'Missing', $this->temporaryDirectory . '/missing.php');
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('Unable to read migration file');
        (new MigrationRepository($this->temporaryDirectory))->load(
            $file, $this->createMock(ConnectionInterface::class)
        );
    }

    public function test_should_return_migrations_sorted_by_version(): void
    {
        // given
        $this->writeMigrationFile('20260714120002_add_clans.php');
        $this->writeMigrationFile('20260714120000_create_users.php');
        $this->writeMigrationFile('20260714120001_add_user_profiles.php');

        // when
        $migrations = (new MigrationRepository($this->temporaryDirectory))->findAll();

        // then
        $this->assertSame(
            ['20260714120000', '20260714120001', '20260714120002'],
            array_map(static function ($migration): string {
                return $migration->getVersion();
            }, $migrations)
        );
        $this->assertSame('create_users', $migrations[0]->getName());
        $this->assertSame('CreateUsers', $migrations[0]->getClassName());
        $this->assertSame('AddUserProfiles', $migrations[1]->getClassName());
    }

    public function test_should_return_empty_list_for_empty_directory(): void
    {
        $this->assertSame(
            [],
            (new MigrationRepository($this->temporaryDirectory))->findAll()
        );
    }

    public function test_should_reject_invalid_file_name(): void
    {
        // given
        $this->writeMigrationFile('create_users.php');

        // then
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('Expected format: YYYYMMDDHHMMSS_migration_name.php');

        // when
        (new MigrationRepository($this->temporaryDirectory))->findAll();
    }

    public function test_should_reject_duplicate_version(): void
    {
        // given
        $this->writeMigrationFile('20260714120000_create_users.php');
        $this->writeMigrationFile('20260714120000_create_clans.php');

        // then
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('More than one migration has version 20260714120000.');

        // when
        (new MigrationRepository($this->temporaryDirectory))->findAll();
    }

    public function test_should_reject_missing_directory(): void
    {
        // given
        $missingDirectory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'missing';

        // then
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage("Migration directory does not exist: {$missingDirectory}");

        // when
        (new MigrationRepository($missingDirectory))->findAll();
    }

    public function test_should_load_valid_migration(): void
    {
        // given
        $this->writeMigrationFile(
            '20260714120000_repository_load_valid_migration.php',
            <<<'PHP'
<?php

use Kgkg\MigrationManager\AbstractMigration;

final class RepositoryLoadValidMigration extends AbstractMigration
{
    public function up(): void
    {
    }

    public function down(): void
    {
    }
}
PHP
        );
        $repository = new MigrationRepository($this->temporaryDirectory);
        $file = $repository->findAll()[0];

        // when
        $migration = $repository->load($file, $this->createMock(ConnectionInterface::class));

        // then
        $this->assertInstanceOf(AbstractMigration::class, $migration);
        $this->assertSame('RepositoryLoadValidMigration', get_class($migration));
    }

    public function test_should_reject_file_without_expected_class(): void
    {
        // given
        $this->writeMigrationFile(
            '20260714120001_repository_missing_expected_class.php',
            '<?php final class DifferentRepositoryMigrationClass {}'
        );
        $repository = new MigrationRepository($this->temporaryDirectory);
        $file = $repository->findAll()[0];

        // then
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('must declare class RepositoryMissingExpectedClass.');

        // when
        $repository->load($file, $this->createMock(ConnectionInterface::class));
    }

    public function test_should_reject_class_not_extending_abstract_migration(): void
    {
        // given
        $this->writeMigrationFile(
            '20260714120002_repository_with_wrong_base_class.php',
            '<?php final class RepositoryWithWrongBaseClass {}'
        );
        $repository = new MigrationRepository($this->temporaryDirectory);
        $file = $repository->findAll()[0];

        // then
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('must extend Kgkg\MigrationManager\AbstractMigration.');

        // when
        $repository->load($file, $this->createMock(ConnectionInterface::class));
    }
}
