<?php

declare(strict_types=1);

namespace MySQLReplication\Tests\Unit\BinLog;

use MySQLReplication\BinLog\BinLogSocketConnect;
use MySQLReplication\Config\ConfigBuilder;
use MySQLReplication\Repository\RepositoryInterface;
use MySQLReplication\Socket\SocketInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
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

        $socket = $this->socketWithBasicStartupResponses();
        $packets = [];
        $socket->method('writeToSocket')
            ->willReturnCallback(static function (string $data) use (&$packets): void {
                $packets[] = $data;
            });
        $socket->expects(self::once())
            ->method('enableTls')
            ->with('127.0.0.1', '', '')
            ->willReturnCallback(static function () use (&$packets): void {
                self::assertSame([self::tlsRequest()], $packets);
            });

        new BinLogSocketConnect($repository, $socket, new NullLogger(), $config);

        self::assertCount(4, $packets);
        self::assertSame(self::tlsRequest(), $packets[0]);
        // Known native-password responses for salt "12345678abcdefghijkl" keep authentication covered.
        self::assertStringStartsWith("\x53\x00\x00\x02", $packets[1]);
        self::assertStringEndsWith("\x14" . hex2bin($authenticationHex) . "mysql_native_password\x00", $packets[1]);
        $host = (string)gethostname();
        // Command, server ID, hostname, username, empty password, port 3307, rank 0 and master ID 0.
        $registration = "\x15\x04\x03\x02\x01" . chr(strlen($host)) . $host
            . "\x07replica\x00\xeb\x0c\x00\x00\x00\x00\x00\x00\x00\x00";
        self::assertSame(pack('V', strlen($registration)) . $registration, $packets[2]);
        // The next packet must still request position 4, flags 0 and the configured server ID and file.
        self::assertSame("\x18\x00\x00\x00\x12\x04\x00\x00\x00\x00\x00\x04\x03\x02\x01binlog.000001", $packets[3]);
    }

    public static function passwords(): iterable
    {
        yield 'nonempty password' => ['registration-secret', '56e848b0ae78f2f8a875291af4fe86cd420c7088'];
        yield 'empty password' => ['', '1c0c56452da0278a71a9e6110e39ddffd8273d77'];
        yield 'password over 255 bytes' => [str_repeat('p', 300), '51e662f61ea77f9cb2d20a4c960824ac9b786d8a'];
        yield 'binary password' => ["nul\x00binary\xff", '8cbd03790010f835834e73b3df08a26ab0824be5'];
    }

    public function testStartupFramesAuthSwitchAndSqlBeforeConsumingBinlogResponse(): void
    {
        $config = (new ConfigBuilder())
            ->withUser('replica')
            ->withPassword('registration-secret')
            ->withSlaveUuid('')
            ->withHeartbeatPeriod(0.5)
            ->withBinLogFileName('binlog.000001')
            ->withBinLogPosition('4')
            ->build();
        $repository = $this->createStub(RepositoryInterface::class);
        $repository->method('getVersion')
            ->willReturn('8.0.36');
        $repository->method('isCheckSum')
            ->willReturn(true);
        $greeting = self::nativePasswordGreeting();
        $authSwitch = "\xfemysql_native_password\x00ZYXWVUTSRQPONMLKJIHG\x00";
        $socket = $this->createMock(SocketInterface::class);
        $packets = [];
        $reads = [
            // Writes so far, requested byte count, server bytes.
            [0, 4, pack('V', strlen($greeting))],
            [0, strlen($greeting), $greeting],
            [2, 4, "\x2c\x00\x00\x03"],
            [2, 44, $authSwitch],
            [4, 4, "\x01\x00\x00\x04"],
            [4, 1, "\x00"],
            [5, 4, "\x01\x00\x00\x01"],
            [5, 1, "\x00"],
            [6, 4, "\x01\x00\x00\x01"],
            [6, 1, "\x00"],
            [7, 4, "\x01\x00\x00\x01"],
            [7, 1, "\x00"],
            [7, 4, "\x0d\x00\x00\x01"],
            [7, 13, "\x00binlog-event"],
        ];
        $socket->expects(self::exactly(count($reads)))->method('readFromSocket')
            ->willReturnCallback(static function (int $length) use (&$reads, &$packets): string {
                [$expectedWriteCount, $expectedLength, $data] = array_shift($reads);
                self::assertCount($expectedWriteCount, $packets);
                self::assertSame($expectedLength, $length);

                return $data;
            });
        $socket->expects(self::exactly(7))->method('writeToSocket')
            ->willReturnCallback(static function (string $data) use (&$packets): void {
                $packets[] = $data;
            });
        $socket->expects(self::once())
            ->method('enableTls')
            ->with('localhost', '', '')
            ->willReturnCallback(static function () use (&$packets): void {
                self::assertSame([self::tlsRequest()], $packets);
            });

        $connection = new BinLogSocketConnect($repository, $socket, new NullLogger(), $config);

        $authPayload = "\x15\xa2\x08\x00\xff\xff\xff\x00\x21" . str_repeat("\x00", 23)
            . "replica\x00\x14" . hex2bin('56e848b0ae78f2f8a875291af4fe86cd420c7088') . "mysql_native_password\x00";
        self::assertSame(self::tlsRequest(), $packets[0]);
        self::assertSame("\x53\x00\x00\x02" . $authPayload, $packets[1]);
        self::assertSame("\x14\x00\x00\x04" . hex2bin('e3957560abd3f8c7291446a2ccdfb1beab96c7f4'), $packets[2]);
        self::assertSame("\x37\x00\x00\x00\x03SET @master_binlog_checksum = @@global.binlog_checksum", $packets[3]);
        self::assertSame("\x29\x00\x00\x00\x03SET @master_heartbeat_period = 500000000", $packets[4]);
        self::assertTrue($connection->getCheckSum());
        self::assertSame("\x00binlog-event", $connection->getResponse());
    }

    #[DataProvider('gtidSets')]
    public function testGtidDumpUsesSerializedSetLength(
        string $gtid,
        string $packetLength,
        string $setLength,
        string $intervals
    ): void {
        $config = (new ConfigBuilder())
            ->withUser('replica')
            ->withSlaveId(0x01020304)
            ->withSlaveUuid('')
            ->withGtid('00112233-4455-6677-8899-aabbccddeeff' . $gtid)
            ->build();
        $repository = $this->createStub(RepositoryInterface::class);
        $repository->method('getVersion')
            ->willReturn('8.0.36');
        $socket = $this->socketWithBasicStartupResponses();
        $packets = [];
        $socket->expects(self::exactly(4))->method('writeToSocket')
            ->willReturnCallback(static function (string $data) use (&$packets): void {
                $packets[] = $data;
            });
        $socket->expects(self::once())
            ->method('enableTls')
            ->with('localhost', '', '')
            ->willReturnCallback(static function () use (&$packets): void {
                self::assertSame([self::tlsRequest()], $packets);
            });

        $connection = new BinLogSocketConnect($repository, $socket, new NullLogger(), $config);

        // Packet length, command, flags, server ID, filename length and bytes, then position 4.
        $prefix = $packetLength . "\x00\x1e\x00\x00\x04\x03\x02\x01\x03\x00\x00\x00\x00\x00\x00"
            . "\x04\x00\x00\x00\x00\x00\x00\x00";
        // Set data length, SID count 1, SID, then the interval count and endpoints.
        $set = $setLength . "\x01\x00\x00\x00\x00\x00\x00\x00"
            . "\x00\x11\x22\x33\x44\x55\x66\x77\x88\x99\xaa\xbb\xcc\xdd\xee\xff" . $intervals;
        self::assertSame(self::tlsRequest(), $packets[0]);
        self::assertSame($prefix . $set, $packets[3]);
        self::assertSame($config->gtid, $connection->getBinLogCurrent()->getGtid());
    }

    public static function gtidSets(): iterable
    {
        $firstInterval = "\x07\x00\x00\x00\x00\x00\x00\x00\x08\x00\x00\x00\x00\x00\x00\x00";
        yield 'one interval' => [
            ':7',
            "\x4a\x00\x00",
            "\x30\x00\x00\x00",
            "\x01\x00\x00\x00\x00\x00\x00\x00" . $firstInterval,
        ];
        yield 'multiple intervals' => [
            ':7:11-15',
            "\x5a\x00\x00",
            "\x40\x00\x00\x00",
            "\x02\x00\x00\x00\x00\x00\x00\x00" . $firstInterval
            . "\x0b\x00\x00\x00\x00\x00\x00\x00\x0f\x00\x00\x00\x00\x00\x00\x00",
        ];
    }

    private function socketWithBasicStartupResponses(): MockObject&SocketInterface
    {
        $greeting = self::nativePasswordGreeting();
        $socket = $this->createMock(SocketInterface::class);
        $socket->expects(self::exactly(8))->method('readFromSocket')
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

        return $socket;
    }

    private static function tlsRequest(): string
    {
        return "\x20\x00\x00\x01\x15\xaa\x08\x00\xff\xff\xff\x00\x21" . str_repeat("\x00", 23);
    }

    private static function nativePasswordGreeting(): string
    {
        // Protocol v10 greeting: connection ID 1, a 20-byte salt and native password authentication.
        return "\x0a8.0.36\x00\x01\x00\x00\x00"
            . "12345678\x00\xff\xff\x21\x02\x00\xff\xff\x15"
            . str_repeat("\x00", 10)
            . "abcdefghijkl\x00mysql_native_password\x00";
    }
}
