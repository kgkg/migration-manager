<?php

namespace Kgkg\MigrationManager\Tests\Unit;

use Kgkg\MigrationManager\Connection\ConnectionException;
use Kgkg\MigrationManager\Connection\MysqliConnection;
use PHPUnit\Framework\TestCase;

final class MysqliConnectionTest extends TestCase
{
    /** @dataProvider invalidConnections */
    public function testInvalidConnectionSettingsFailBeforeConnecting(array $parameters): void
    {
        $this->expectException(ConnectionException::class);
        MysqliConnection::connect($parameters);
    }

    public function invalidConnections(): array
    {
        $valid = ['database' => 'test', 'username' => 'test', 'password' => ''];
        return [
            [[]], [array_replace($valid, ['database' => null])],
            [array_replace($valid, ['host' => ''])], [array_replace($valid, ['port' => '3306'])],
            [array_replace($valid, ['port' => 0])], [array_replace($valid, ['port' => 65536])],
            [array_replace($valid, ['charset' => []])], [array_replace($valid, ['extra' => true])],
            [array_replace($valid, ['username' => "bad\0name"])],
            [array_replace($valid, ['ssl_ca' => null])],
            [array_replace($valid, ['ssl_ca' => false])],
            [array_replace($valid, ['ssl_ca' => ''])],
            [array_replace($valid, ['ssl_ca' => __DIR__])],
            [array_replace($valid, ['ssl_ca' => __DIR__ . '/missing-ca.pem'])],
            [array_replace($valid, ['ssl_ca' => "bad\0path"])],
        ];
    }

    /** @dataProvider invalidParameters */
    public function testInvalidParametersFailBeforeQuerying(array $parameters): void
    {
        $db = new MysqliConnection(mysqli_init());
        $this->expectException(ConnectionException::class);
        $db->executePrepared('SELECT ?', $parameters);
    }

    public function invalidParameters(): array
    {
        return [[['named' => 1]], [[1 => 1]], [[[1]]], [[new \stdClass()]]];
    }

    public function testResourceParameterIsRejected(): void
    {
        $resource = fopen('php://memory', 'r+');
        try {
            $this->expectException(ConnectionException::class);
            (new MysqliConnection(mysqli_init()))->fetchAll('SELECT ?', [$resource]);
        } finally {
            fclose($resource);
        }
    }
}
