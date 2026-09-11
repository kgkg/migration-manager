<?php

namespace Kgkg\MigrationManager\Connection;

/** A MySQL session. Connections passed to the constructor remain caller-owned. */
final class MysqliConnection implements ConnectionInterface
{
    private \mysqli $connection;
    private bool $owned = false;

    public function __construct(\mysqli $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Create an owned connection. Required: database, username and password.
     * Optional: host (127.0.0.1), port (3306), charset (utf8mb4), ssl_ca (verified TLS).
     *
     * @param array<string, mixed> $parameters
     */
    public static function connect(array $parameters): self
    {
        $parameters += ['host' => '127.0.0.1', 'port' => 3306, 'charset' => 'utf8mb4'];
        foreach (['host', 'database', 'username', 'password', 'charset'] as $key) {
            if (!isset($parameters[$key]) || !is_string($parameters[$key])
                || ($key !== 'password' && trim($parameters[$key]) === '')
                || strpos($parameters[$key], "\0") !== false) {
                throw new ConnectionException('Invalid connection parameter: ' . $key . '.');
            }
        }
        if (!is_int($parameters['port']) || $parameters['port'] < 1 || $parameters['port'] > 65535) {
            throw new ConnectionException('Connection port must be an integer between 1 and 65535.');
        }
        if (strncasecmp($parameters['host'], 'p:', 2) === 0) {
            throw new ConnectionException('Persistent connections are not supported. Remove the p: host prefix.');
        }
        if (array_diff(array_keys($parameters), ['host', 'port', 'database', 'username', 'password', 'charset', 'ssl_ca'])) {
            throw new ConnectionException('Unknown connection parameter.');
        }
        $tls = array_key_exists('ssl_ca', $parameters);
        if ($tls && (!is_string($parameters['ssl_ca']) || $parameters['ssl_ca'] === ''
            || strpos($parameters['ssl_ca'], "\0") !== false
            || !is_file($parameters['ssl_ca']) || !is_readable($parameters['ssl_ca']))) {
            throw new ConnectionException('Connection ssl_ca must be a readable CA certificate file.');
        }

        $native = null;
        try {
            $native = mysqli_init();
            if ($native === false) {
                throw new ConnectionException('Unable to initialize MySQLi.');
            }
            if ($tls && !@$native->ssl_set(null, null, $parameters['ssl_ca'], null, null)) {
                throw new ConnectionException('Unable to configure verified TLS.');
            }
            if (@$native->real_connect($parameters['host'], $parameters['username'], $parameters['password'],
                $parameters['database'], $parameters['port'], null,
                $tls ? MYSQLI_CLIENT_SSL | MYSQLI_CLIENT_SSL_VERIFY_SERVER_CERT : 0) === false) {
                throw new ConnectionException('Unable to connect to MySQL: ' . $native->connect_error, $native->connect_errno);
            }
            if (@$native->set_charset($parameters['charset']) === false) {
                throw new ConnectionException('Unable to set the connection charset: ' . $native->error, $native->errno);
            }
            $adapter = new self($native);
            $adapter->owned = true;
            if ($tls) {
                $status = $adapter->fetchAll("SHOW SESSION STATUS LIKE 'Ssl_cipher'");
                if (empty($status[0]['Value'])) {
                    throw new ConnectionException('TLS was required but the server did not negotiate encryption.');
                }
            }
            return $adapter;
        } catch (\Throwable $error) {
            if ($native instanceof \mysqli) {
                try {
                    @$native->close();
                } catch (\Throwable $ignored) {
                    // Cleanup must not replace the connection failure.
                }
            }
            throw self::failure($error);
        }
    }

    public function __destruct()
    {
        if ($this->owned) {
            try {
                @$this->connection->close();
            } catch (\Throwable $ignored) {
                // Destruction must not mask an active exception.
            }
        }
    }

    public function execute(string $sql): void
    {
        if (trim($sql) === '') {
            return;
        }
        try {
            if (@$this->connection->multi_query($sql) === false) {
                throw $this->nativeFailure();
            }
            do {
                $result = @$this->connection->store_result();
                if ($result instanceof \mysqli_result) {
                    $result->free();
                } elseif ($this->connection->field_count !== 0) {
                    throw $this->nativeFailure();
                }
                if (!$this->connection->more_results()) {
                    break;
                }
                if (@$this->connection->next_result() === false) {
                    throw $this->nativeFailure();
                }
            } while (true);
        } catch (\Throwable $error) {
            throw self::failure($error);
        }
    }

    public function executePrepared(string $sql, array $parameters = []): void
    {
        $this->query($sql, $parameters, false);
    }

    public function fetchValue(string $sql, array $parameters = [])
    {
        $rows = $this->query($sql, $parameters, true, true);
        return $rows === [] ? null : array_values($rows[0])[0];
    }

    public function fetchAll(string $sql, array $parameters = []): array
    {
        return $this->query($sql, $parameters, true);
    }

    public function hasColumn(string $tableName, string $columnName): bool
    {
        return (int)$this->fetchValue(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?',
            [$this->getDatabaseName(), $tableName, $columnName]
        ) > 0;
    }

    public function getDatabaseName(): string
    {
        $database = $this->fetchValue('SELECT DATABASE()');
        if (!is_string($database) || $database === '') {
            throw new ConnectionException('No database is selected.');
        }
        return $database;
    }

    public function assertMigrationSession(): void
    {
        if ((int)$this->fetchValue('SELECT @@SESSION.autocommit') !== 1) {
            throw new ConnectionException('Migrations require autocommit enabled and no active transaction.');
        }
        // MySQL does not expose @@in_transaction. A savepoint exists only inside
        // a transaction; unlike DDL or START TRANSACTION this probe never commits.
        $savepoint = 'migration_probe_' . bin2hex(random_bytes(16));
        $this->execute('SAVEPOINT `' . $savepoint . '`');
        try {
            $this->execute('RELEASE SAVEPOINT `' . $savepoint . '`');
        } catch (ConnectionException $error) {
            if ($error->getCode() === 1305) {
                return; // No transaction, hence no savepoint to release.
            }
            throw $error;
        }
        throw new ConnectionException('Migrations cannot run inside an active transaction.');
    }

    public function getMigrationLockName(string $tableName): string
    {
        $database = $this->getDatabaseName();
        if ((int)$this->fetchValue('SELECT @@lower_case_table_names') !== 0) {
            // Use the server's Unicode case conversion for database identifiers.
            $database = (string)$this->fetchValue('SELECT LOWER(?)', [$database]);
            $tableName = strtolower($tableName);
        }
        return 'migration_manager_' . sha1($database . "\0" . $tableName);
    }

    /** @return list<array<string, null|int|float|string>> */
    private function query(string $sql, array $parameters, bool $fetch, bool $firstColumn = false): array
    {
        $types = '';
        $index = 0;
        $boundValues = [];
        foreach ($parameters as $key => $value) {
            if ($key !== $index++ || (!is_null($value) && !is_scalar($value))) {
                throw new ConnectionException('SQL parameters must be a positional list of null, bool, int, float or string values.');
            }
            if (is_bool($value)) {
                $value = (int)$value;
            }
            $types .= is_int($value) ? 'i' : (is_float($value) ? 'd' : 's');
            $boundValues[] = $value;
        }

        $statement = null;
        try {
            $statement = @$this->connection->prepare($sql);
            if ($statement === false) {
                throw $this->nativeFailure();
            }
            if ($statement->param_count !== count($parameters)) {
                throw new ConnectionException('SQL placeholder count does not match the number of parameters.');
            }
            if ($boundValues !== [] && @$statement->bind_param($types, ...$boundValues) === false) {
                throw $this->statementFailure($statement);
            }
            if (@$statement->execute() === false) {
                throw $this->statementFailure($statement);
            }
            $rows = [];
            $missingResult = $fetch && $statement->field_count === 0;
            $first = true;
            do {
                // PHP 7.4 can retain the previous field_count for a CALL's final OK packet.
                $metadata = @$statement->result_metadata();
                if ($metadata instanceof \mysqli_result) {
                    $metadata->free();
                    if (@$statement->store_result() === false) {
                        throw $this->statementFailure($statement);
                    }
                    if ($first && $fetch) {
                        $rows = $this->readRows($statement, $firstColumn);
                    }
                }
                // next_result releases the prior buffer. Calling free_result first
                // breaks advancing prepared CALL results on PHP 7.4/mysqlnd.
                $first = false;
                if (!$statement->more_results()) {
                    break;
                }
                if (@$statement->next_result() === false) {
                    throw $this->statementFailure($statement);
                }
            } while (true);
            if ($missingResult) {
                throw new ConnectionException('The SQL statement did not return a result set.');
            }
            return $rows;
        } catch (\Throwable $error) {
            throw self::failure($error);
        } finally {
            if ($statement instanceof \mysqli_stmt) {
                try {
                    @$statement->close();
                } catch (\Throwable $ignored) {
                    // Preserve the original operation failure during cleanup.
                }
            }
        }
    }

    /** Materialize result values without exposing a native result object. */
    private function readRows(\mysqli_stmt $statement, bool $firstColumn): array
    {
        $metadata = @$statement->result_metadata();
        if ($metadata === false) {
            throw $this->statementFailure($statement);
        }
        try {
            $fields = $metadata->fetch_fields();
        } finally {
            $metadata->free();
        }
        $values = array_fill(0, count($fields), null);
        if (@$statement->bind_result(...$values) === false) {
            throw $this->statementFailure($statement);
        }
        $rows = [];
        while (($status = @$statement->fetch()) === true) {
            $row = [];
            foreach ($fields as $index => $field) {
                $row[$field->name] = $values[$index];
                if ($firstColumn) {
                    break;
                }
            }
            $rows[] = $row;
        }
        if ($status === false) {
            throw $this->statementFailure($statement);
        }
        return $rows;
    }

    private function nativeFailure(): ConnectionException
    {
        return new ConnectionException('MySQL operation failed: ' . $this->connection->error, $this->connection->errno);
    }

    private function statementFailure(\mysqli_stmt $statement): ConnectionException
    {
        return new ConnectionException('MySQL statement failed: ' . $statement->error, $statement->errno);
    }

    private static function failure(\Throwable $error): ConnectionException
    {
        return $error instanceof ConnectionException ? $error
            : new ConnectionException('MySQL operation failed: ' . $error->getMessage(), (int)$error->getCode(), $error);
    }
}
