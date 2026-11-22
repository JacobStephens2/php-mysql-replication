<?php

declare(strict_types=1);

namespace MySQLReplication\Tests\Unit\BinLog;

use MySQLReplication\BinLog\BinLogException;
use MySQLReplication\BinLog\BinLogPacketStream;
use MySQLReplication\Socket\SocketInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BinLogPacketStreamTest extends TestCase
{
    public function testWritesPayloadWithLittleEndianLengthAndSequenceId(): void
    {
        $payload = str_repeat('a', 0x010203);
        $socket = $this->createMock(SocketInterface::class);
        $socket->expects(self::once())->method('writeToSocket')->with("\x03\x02\x01\x03" . $payload);

        (new BinLogPacketStream($socket))->writePayload($payload, 3);
    }

    public function testWritesPayloadWithDefaultSequenceId(): void
    {
        $socket = $this->createMock(SocketInterface::class);
        $socket->expects(self::once())->method('writeToSocket')->with("\x02\x00\x00\x00\x00a");

        (new BinLogPacketStream($socket))->writePayload("\x00a");
    }

    #[DataProvider('acceptedStatuses')]
    public function testCheckedResponseKeepsStatusByte(string $status): void
    {
        $response = $status . "\xff\x00";
        $stream = $this->streamWithReads([[4, "\x03\x00\x00\x01"], [3, $response]]);

        self::assertSame($response, $stream->readResponse());
    }

    public static function acceptedStatuses(): iterable
    {
        yield 'OK' => ["\x00"];
        yield 'authentication more data' => ["\x01"];
        yield 'authentication switch' => ["\xfe"];
    }

    public function testUncheckedResponseKeepsGreetingBytes(): void
    {
        $greeting = "\x0a8.0.36\x00";
        $stream = $this->streamWithReads([[4, "\x08\x00\x00\x00"], [8, $greeting]]);

        self::assertSame($greeting, $stream->readResponse(false));
    }

    public function testServerErrorIsRaisedBeforeReadingContinuation(): void
    {
        $message = str_pad('Access denied', 0xffffff - 9, 'x');
        $stream = $this->streamWithReads([[4, "\xff\xff\xff\x01"], [0xffffff, "\xff\x15\x04#28000" . $message]]);

        try {
            $stream->readResponse();
            self::fail('The server error was not raised.');
        } catch (BinLogException $exception) {
            self::assertSame(1045, $exception->getCode());
            self::assertSame($message, $exception->getMessage());
        }
    }

    public function testReadsShortContinuationTail(): void
    {
        $firstPacket = "\x00" . str_repeat('a', 0xfffffe);
        $stream = $this->streamWithReads([
            [4, "\xff\xff\xff\x01"],
            [0xffffff, $firstPacket],
            [4, "\x02\x00\x00\x02"],
            [2, "\xffz"],
        ]);

        self::assertSame($firstPacket . "\xffz", $stream->readResponse());
    }

    public function testExactLimitResponseLeavesSocketAtNextResponse(): void
    {
        $firstPacket = "\x00" . str_repeat('a', 0xfffffe);
        $stream = $this->streamWithReads([
            [4, "\xff\xff\xff\x01"],
            [0xffffff, $firstPacket],
            [4, "\x00\x00\x00\x02"],
            [4, "\x03\x00\x00\x01"],
            [3, "\x00ok"],
        ]);

        self::assertSame($firstPacket, $stream->readResponse());
        self::assertSame("\x00ok", $stream->readResponse());
    }

    public function testEmptyInitialHeaderReturnsEmptyResponse(): void
    {
        $stream = $this->streamWithReads([[4, '']]);

        self::assertSame('', $stream->readResponse());
    }

    public function testEmptyContinuationHeaderReturnsDataAlreadyRead(): void
    {
        $firstPacket = "\x00" . str_repeat('a', 0xfffffe);
        $stream = $this->streamWithReads([[4, "\xff\xff\xff\x01"], [0xffffff, $firstPacket], [4, '']]);

        self::assertSame($firstPacket, $stream->readResponse());
    }

    /** @param list<array{int, string}> $reads */
    private function streamWithReads(array $reads): BinLogPacketStream
    {
        $socket = $this->createMock(SocketInterface::class);
        $socket->expects(self::exactly(count($reads)))->method('readFromSocket')
            ->willReturnCallback(static function (int $length) use (&$reads): string {
                [$expectedLength, $data] = array_shift($reads);
                self::assertSame($expectedLength, $length);

                return $data;
            });

        return new BinLogPacketStream($socket);
    }
}
