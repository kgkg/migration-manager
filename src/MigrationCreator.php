<?php

namespace Kgkg\MigrationManager;

final class MigrationCreator
{
    private string $directory;

    /**
     * @var callable
     */
    private $currentTimestampProvider;

    public function __construct(string $directory, ?callable $currentTimestampProvider = null)
    {
        $this->directory = rtrim($directory, '/\\');
        $this->currentTimestampProvider = $currentTimestampProvider ?? static function (): int {
            return time();
        };
    }

    public function create(string $name): string
    {
        $migrationName = $this->normalizeName($name);
        $className = $this->classNameFromMigrationName($migrationName);

        $this->ensureDirectoryExists();
        $duplicates = glob($this->directory . DIRECTORY_SEPARATOR . '*_' . $migrationName . '.php');
        if ($duplicates === false) {
            throw new MigrationException("Unable to read the migration directory: {$this->directory}");
        }
        if ($duplicates !== []) {
            throw new MigrationException("A migration named {$migrationName} already exists.");
        }

        $timestamp = (int)call_user_func($this->currentTimestampProvider);
        $version = date('YmdHis', $timestamp);
        $path = $this->directory . DIRECTORY_SEPARATOR . $version . '_' . $migrationName . '.php';
        while ($this->versionExists($version)) {
            $timestamp++;
            $version = date('YmdHis', $timestamp);
            $path = $this->directory . DIRECTORY_SEPARATOR . $version . '_' . $migrationName . '.php';
        }

        $contents = $this->buildContents($className);
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new MigrationException("Unable to create the migration file: {$path}");
        }

        return $path;
    }

    private function versionExists(string $version): bool
    {
        $matches = glob($this->directory . DIRECTORY_SEPARATOR . $version . '_*.php');
        if ($matches === false) {
            throw new MigrationException("Unable to read the migration directory: {$this->directory}");
        }

        return $matches !== [];
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name);
        $name = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '_', (string)$name));
        $name = trim($name, '_');

        if ($name === '' || preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new MigrationException(
                'The migration name must start with a letter and contain only letters, digits, spaces, "_" or "-".'
            );
        }

        return $name;
    }

    private function classNameFromMigrationName(string $name): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
    }

    private function ensureDirectoryExists(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        if (mkdir($this->directory, 0775, true) === false && is_dir($this->directory) === false) {
            throw new MigrationException("Unable to create the migration directory: {$this->directory}");
        }
    }

    private function buildContents(string $className): string
    {
        return <<<PHP
<?php

use Kgkg\MigrationManager\AbstractMigration;

final class {$className} extends AbstractMigration
{
    public function up(): void
    {
        // Apply changes with \$this->execute('CREATE TABLE ...');
    }

    public function down(): void
    {
        // Revert changes with \$this->execute('DROP TABLE ...');
    }
}
PHP;
    }
}
