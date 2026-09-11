<?php

use Kgkg\MigrationManager\Connection\MysqliConnection;

return [
    // Relative paths are resolved from this configuration file, not the process CWD.
    'migrations_path' => 'db/migrations',

    // Called only by database operations. getenv() reads the process environment.
    // Applications are responsible for loading .env, if needed, in their bootstrap.
    'connection' => static function (): MysqliConnection {
        return MysqliConnection::connect([
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('DB_PORT') ?: 3306),
            'database' => getenv('DB_DATABASE'),
            'username' => getenv('DB_USERNAME'),
            'password' => getenv('DB_PASSWORD'),
            'charset' => 'utf8mb4',
            // For remote databases: require TLS and verify the server certificate.
            // 'ssl_ca' => __DIR__ . '/certificates/mysql-ca.pem',
        ]);
    },

    // History table in the configured database; this is also the default if omitted.
    // Created automatically by run/rollback if missing. Choose before the first run.
    'table_name' => 'schema_migrations',
    'lock_timeout' => 0,

    // Optional application setup, executed once before the connection factory.
    // 'bootstrap' => 'bootstrap.php',
];
