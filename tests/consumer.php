<?php

// Run with the same explicit, dedicated database settings as integration tests.
require __DIR__ . '/Integration/bootstrap.php';

use Kgkg\MigrationManager\Connection\MysqliConnection;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$directory = $root . '/.test-runtime/consumer ' . bin2hex(random_bytes(6));
mkdir($directory, 0777, true);
// Mirror only distributable files: never copy the checkout's vendor or database runtime.
$package = $directory . '/package';
mkdir($package);
foreach (['composer.json', 'LICENSE', 'README.md', 'src', 'bin', 'examples'] as $entry) {
    if (is_file($root . '/' . $entry)) {
        copy($root . '/' . $entry, $package . '/' . $entry);
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $entry,
        FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    mkdir($package . '/' . $entry);
    foreach ($iterator as $file) {
        $target = $package . '/' . $entry . '/' . $iterator->getSubPathName();
        if ($file->isDir()) {
            mkdir($target);
        } else {
            copy($file->getPathname(), $target);
        }
    }
}
$composer = getenv('COMPOSER_BINARY') ?: 'composer';
$composerCommand = substr($composer, -5) === '.phar' ? [PHP_BINARY, $composer] : [$composer];
$run = static function (array $command) use ($directory): string {
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $directory . '/stdout.log', 'w'],
        2 => ['file', $directory . '/stderr.log', 'w']], $pipes, $directory);
    check(is_resource($process), 'Could not start consumer command.');
    fclose($pipes[0]);
    $code = proc_close($process);
    $output = file_get_contents($directory . '/stdout.log');
    $error = file_get_contents($directory . '/stderr.log');
    check($code === 0, implode(' ', $command) . " failed:\n" . $output . $error);
    return $output;
};
file_put_contents($directory . '/composer.json', json_encode([
    'name' => 'migration-manager/consumer-test',
    'repositories' => [['type' => 'path', 'url' => str_replace('\\', '/', $package),
        'options' => ['symlink' => false, 'versions' => ['kgkg/migration-manager' => '1.0.0']]]],
    'require' => ['kgkg/migration-manager' => '1.0.0'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$install = static function (string $command) use ($run, $composerCommand): void {
    $run(array_merge($composerCommand, [$command, '--no-interaction', '--no-progress', '--no-dev']));
};
$install('install');
check(!is_dir($directory . '/vendor/kgkg/migration-manager/vendor'), 'Installed package contains development dependencies.');
$proxy = $directory . '/vendor/bin/migration-manager';
check(is_file($proxy), 'Composer did not generate the CLI proxy.');
$cli = static function (array $arguments) use ($run, $proxy): string {
    return $run(array_merge(PHP_OS_FAMILY === 'Windows'
        ? ['cmd.exe', '/d', '/c', str_replace('/', '\\', $proxy) . '.bat']
        : [$proxy], $arguments));
};
$run([PHP_BINARY, '-r', 'require "vendor/autoload.php"; exit(class_exists("Kgkg\\MigrationManager\\MigrationManager") ? 0 : 1);']);
$cli(['--help']);
$cli(['init']);
$cli(['create', 'consumer_probe']);
$files = glob($directory . '/db/migrations/*_consumer_probe.php');
check(count($files) === 1, 'Consumer migration was not generated.');
$suffix = bin2hex(random_bytes(6));
$history = 'consumer_history_' . $suffix;
$effects = 'consumer_effects_' . $suffix;
$config = file_get_contents($directory . '/migration.config.php');
$config = str_replace('schema_migrations', $history, $config);
foreach (['HOST', 'PORT', 'DATABASE', 'USERNAME', 'PASSWORD'] as $key) {
    $config = str_replace('DB_' . $key, 'MIGRATION_MANAGER_TEST_' . $key, $config);
}
file_put_contents($directory . '/migration.config.php', $config);
$migration = file_get_contents($files[0]);
$migration = str_replace("// Apply changes with \$this->execute('CREATE TABLE ...');",
    '$this->execute(' . var_export("CREATE TABLE `$effects` (id INT)", true) . ');', $migration);
// Use the generated class and imports while supplying reversible SQL.
$migration = str_replace('throw new \\Kgkg\\MigrationManager\\IrreversibleMigrationException();',
    '$this->execute(' . var_export("DROP TABLE `$effects`", true) . ');', $migration);
file_put_contents($files[0], $migration);
$db = MysqliConnection::connect([
    'host' => getenv('MIGRATION_MANAGER_TEST_HOST'), 'port' => (int)getenv('MIGRATION_MANAGER_TEST_PORT'),
    'database' => getenv('MIGRATION_MANAGER_TEST_DATABASE'), 'username' => getenv('MIGRATION_MANAGER_TEST_USERNAME'),
    'password' => getenv('MIGRATION_MANAGER_TEST_PASSWORD'),
]);
$exists = static function (string $table) use ($db): bool {
    return (int)$db->fetchValue('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$table]) === 1;
};
try {
    $install('install');
    $install('update');
    check(!$exists($history) && !$exists($effects), 'Composer automatically executed a migration.');
    check(strpos($cli(['show']), 'consumer_probe') !== false, 'Show did not list the pending migration.');
    check(!$exists($history), 'Show created migration history.');
    $cli(['run']);
    check($exists($effects) && (int)$db->fetchValue("SELECT COUNT(*) FROM `$history`") === 1, 'Run did not persist migration and history.');
    $cli(['run']);
    check((int)$db->fetchValue("SELECT COUNT(*) FROM `$history`") === 1, 'Run repeated an applied migration.');
    $cli(['rollback', '--steps=1']);
    check(!$exists($effects) && (int)$db->fetchValue("SELECT COUNT(*) FROM `$history`") === 0, 'Rollback did not revert migration and history.');
    echo 'Consumer installation and CLI cycle passed on PHP ' . PHP_VERSION . ' (' . PHP_OS_FAMILY . ").\n";
} finally {
    $db->execute("DROP TABLE IF EXISTS `$history`, `$effects`");
}
