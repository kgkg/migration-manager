<?php

namespace Kgkg\MigrationManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class IntegrationBootstrapTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     * @dataProvider settings
     */
    public function testExplicitDatabaseSettingsAreRequired(array $overrides, ?string $error): void
    {
        $settings = array_replace([
            'ALLOW_DESTRUCTIVE' => '1',
            'HOST' => '127.0.0.1',
            'PORT' => '3306',
            'DATABASE' => 'migration_manager_test_bootstrap',
            'USERNAME' => 'test_user',
            'PASSWORD' => '',
        ], $overrides);

        foreach ($settings as $name => $value) {
            putenv('MIGRATION_MANAGER_TEST_' . $name . ($value === null ? '' : '=' . $value));
        }
        if ($error !== null) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage($error);
        }

        require dirname(__DIR__) . '/Integration/bootstrap.php';
        self::assertFalse(class_exists('Database', false));
    }

    public function settings(): array
    {
        return [
            'explicit settings without connecting' => [[], null],
            'missing opt-in' => [['ALLOW_DESTRUCTIVE' => null], 'ALLOW_DESTRUCTIVE=1'],
            'missing password' => [['PASSWORD' => null], 'Missing integration setting'],
            'application database' => [['DATABASE' => 'application'], 'Use a dedicated database'],
            'invalid port' => [['PORT' => '3306oops'], 'port must be an integer'],
            'out of range port' => [['PORT' => '65536'], 'port must be an integer'],
        ];
    }
}
