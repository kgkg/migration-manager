<?php

namespace Kgkg\MigrationManager\Tests\Integration;

use Kgkg\MigrationManager\Connection\ConnectionException;
use Kgkg\MigrationManager\Connection\MysqliConnection;
use PHPUnit\Framework\TestCase;

final class TlsConnectionTest extends TestCase
{
    public function test_persistent_pool_cannot_bypass_new_ca_verification(): void
    {
        $parameters = $this->parameters('server');
        $native = mysqli_init();
        $native->ssl_set(null, null, $parameters['ssl_ca'], null, null);
        $native->real_connect('p:' . $parameters['host'], $parameters['username'], $parameters['password'],
            $parameters['database'], $parameters['port'], null,
            MYSQLI_CLIENT_SSL | MYSQLI_CLIENT_SSL_VERIFY_SERVER_CERT);
        $native->close();
        $parameters = $this->parameters('untrusted');
        $parameters['host'] = 'p:' . $parameters['host'];
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Persistent connections are not supported');
        MysqliConnection::connect($parameters);
    }

    private function parameters(string $certificate): array
    {
        $directory = getenv('MIGRATION_MANAGER_TEST_TLS_DIR');
        if (!$directory) {
            $this->markTestSkipped('Set MIGRATION_MANAGER_TEST_TLS_DIR and configure the dedicated server certificates.');
        }
        return [
            'host' => getenv('MIGRATION_MANAGER_TEST_HOST'),
            'port' => (int)getenv('MIGRATION_MANAGER_TEST_PORT'),
            'database' => getenv('MIGRATION_MANAGER_TEST_DATABASE'),
            'username' => getenv('MIGRATION_MANAGER_TEST_USERNAME'),
            'password' => getenv('MIGRATION_MANAGER_TEST_PASSWORD'),
            'ssl_ca' => $directory . '/' . $certificate . '.pem',
        ];
    }

    public function test_verified_connection_negotiates_encryption(): void
    {
        $db = MysqliConnection::connect($this->parameters('server'));
        $status = $db->fetchAll("SHOW SESSION STATUS LIKE 'Ssl_cipher'");
        $this->assertNotEmpty($status[0]['Value']);
        $this->assertSame(1, (int)$db->fetchValue('SELECT 1'));
    }

    public function test_untrusted_certificate_is_rejected(): void
    {
        $parameters = $this->parameters('untrusted');
        $this->expectException(ConnectionException::class);
        MysqliConnection::connect($parameters);
    }
}
