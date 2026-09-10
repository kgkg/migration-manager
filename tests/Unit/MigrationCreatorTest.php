<?php

namespace Kgkg\MigrationManager\Tests\Unit;

use Kgkg\MigrationManager\MigrationCreator;
use Kgkg\MigrationManager\MigrationException;

final class MigrationCreatorTest extends MigrationManagerTestCase
{
    private const CURRENT_TIMESTAMP = 1784023200;

    /**
     * @dataProvider provideMigrationNames
     */
    public function test_should_normalize_name_and_create_migration(
        string $providedName,
        string $expectedName,
        string $expectedClassName
    ): void {
        // given
        $creator = $this->createCreator();

        // when
        $path = $creator->create($providedName);

        // then
        $expectedVersion = date('YmdHis', self::CURRENT_TIMESTAMP);
        $this->assertSame("{$expectedVersion}_{$expectedName}.php", basename($path));
        $this->assertFileExists($path);

        $contents = (string)file_get_contents($path);
        $this->assertStringContainsString("final class {$expectedClassName} extends AbstractMigration", $contents);
        $this->assertStringContainsString('public function up(): void', $contents);
        $this->assertStringContainsString('public function down(): void', $contents);
    }

    public function provideMigrationNames(): array
    {
        return [
            'spaces' => ['create user profiles', 'create_user_profiles', 'CreateUserProfiles'],
            'dashes' => ['create-user-profiles', 'create_user_profiles', 'CreateUserProfiles'],
            'camel case' => ['CreateUserProfiles', 'create_user_profiles', 'CreateUserProfiles'],
            'surrounding separators' => ['__create user profiles--', 'create_user_profiles', 'CreateUserProfiles'],
        ];
    }

    /**
     * @dataProvider provideInvalidMigrationNames
     */
    public function test_should_reject_invalid_name(string $name): void
    {
        // then
        $this->expectException(MigrationException::class);

        // when
        $this->createCreator()->create($name);
    }

    public function provideInvalidMigrationNames(): array
    {
        return [
            'empty' => [''],
            'only separators' => ['---___'],
            'starts with a number' => ['123 create users'],
        ];
    }

    public function test_should_create_missing_directory(): void
    {
        // given
        $migrationsDirectory = $this->temporaryDirectory
            . DIRECTORY_SEPARATOR . 'nested'
            . DIRECTORY_SEPARATOR . 'migrations';
        $creator = $this->createCreator($migrationsDirectory);

        // when
        $path = $creator->create('create users');

        // then
        $this->assertDirectoryExists($migrationsDirectory);
        $this->assertFileExists($path);
    }

    public function test_should_reject_duplicate_normalized_name(): void
    {
        // given
        $creator = $this->createCreator();
        $creator->create('create-users');

        // then
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('A migration named create_users already exists.');

        // when
        $creator->create('create users');
    }

    public function test_should_increment_version_when_another_migration_uses_current_timestamp(): void
    {
        // given
        $creator = $this->createCreator();

        // when
        $firstPath = $creator->create('create users');
        $secondPath = $creator->create('create clans');

        // then
        $this->assertSame(
            date('YmdHis', self::CURRENT_TIMESTAMP) . '_create_users.php',
            basename($firstPath)
        );
        $this->assertSame(
            date('YmdHis', self::CURRENT_TIMESTAMP + 1) . '_create_clans.php',
            basename($secondPath)
        );
    }

    private function createCreator(?string $directory = null): MigrationCreator
    {
        return new MigrationCreator(
            $directory ?? $this->temporaryDirectory,
            static function (): int {
                return self::CURRENT_TIMESTAMP;
            }
        );
    }
}
