<?php

declare(strict_types=1);

namespace MySQLReplication\Tests\Unit\BinLog;

use MySQLReplication\BinLog\BinLogSocketConnect;
use MySQLReplication\Config\ConfigBuilder;
use MySQLReplication\Repository\RepositoryInterface;
use MySQLReplication\Socket\SocketInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class BinLogSocketConnectTest extends TestCase
{
    #[DataProvider('passwords')]
    public function testReplicaRegistrationOmitsPasswordAndHasCorrectLength(
        string $password,
        string $authenticationHex
    ): void {
        $config = (new ConfigBuilder())
            ->withHost('127.0.0.1')
            ->withPort(3307)
            ->withUser('replica')
            ->withPassword($password)
            ->withSlaveId(0x01020304)
            ->withSlaveUuid('')
            ->withBinLogFileName('binlog.000001')
            ->withBinLogPosition('4')
            ->build();
        $repository = $this->createStub(RepositoryInterface::class);
        $repository->method('getVersion')
            ->willReturn('8.0.36');
        $repository->method('isCheckSum')
            ->willReturn(false);

        // Protocol v10 greeting: connection ID 1, a 20-byte salt and native password authentication.
        $greeting = "\x0a8.0.36\x00\x01\x00\x00\x00"
            . "12345678\x00\xff\xff\x21\x02\x00\xff\xff\x15"
            . str_repeat("\x00", 10)
            . "abcdefghijkl\x00mysql_native_password\x00";
        $socket = $this->createMock(SocketInterface::class);
        $socket->method('readFromSocket')
            ->willReturnOnConsecutiveCalls(
                pack('V', strlen($greeting)),
                $greeting,
                "\x01\x00\x00\x02",
                "\x00",
                "\x01\x00\x00\x01",
                "\x00",
                "\x01\x00\x00\x01",
                "\x00"
            );
        $packets = [];
        $socket->method('writeToSocket')
            ->willReturnCallback(static function (string $data) use (&$packets): void {
                $packets[] = $data;
            });

        new BinLogSocketConnect($repository, $socket, new NullLogger(), $config);

        self::assertCount(3, $packets);
        // Known native-password responses for salt "12345678abcdefghijkl" keep authentication covered.
        self::assertStringEndsWith("\x14" . hex2bin($authenticationHex) . "mysql_native_password\x00", $packets[0]);
        $host = (string)gethostname();
        // Command, server ID, hostname, username, empty password, port 3307, rank 0 and master ID 0.
        $registration = "\x15\x04\x03\x02\x01" . chr(strlen($host)) . $host
            . "\x07replica\x00\xeb\x0c\x00\x00\x00\x00\x00\x00\x00\x00";
        self::assertSame(pack('V', strlen($registration)) . $registration, $packets[1]);
        // The next packet must still request position 4, flags 0 and the configured server ID and file.
        self::assertSame("\x18\x00\x00\x00\x12\x04\x00\x00\x00\x00\x00\x04\x03\x02\x01binlog.000001", $packets[2]);
    }

    public static function passwords(): iterable
    {
        yield 'nonempty password' => ['registration-secret', '56e848b0ae78f2f8a875291af4fe86cd420c7088'];
        yield 'empty password' => ['', '1c0c56452da0278a71a9e6110e39ddffd8273d77'];
        yield 'password over 255 bytes' => [str_repeat('p', 300), '51e662f61ea77f9cb2d20a4c960824ac9b786d8a'];
        yield 'binary password' => ["nul\x00binary\xff", '8cbd03790010f835834e73b3df08a26ab0824be5'];
    }
}
