<?php

declare(strict_types=1);

namespace MySQLReplication\BinLog;

use MySQLReplication\BinaryDataReader\BinaryDataReader;
use MySQLReplication\Socket\SocketInterface;

/** @internal */
final class BinLogPacketStream
{
    private const HEADER_LENGTH = 4;
    private const MAX_PAYLOAD_LENGTH = 0xffffff;

    public function __construct(private readonly SocketInterface $socket)
    {
    }

    public function writePayload(string $payload, int $sequenceId = 0): void
    {
        $length = substr(pack('V', strlen($payload)), 0, 3);
        $this->socket->writeToSocket($length . chr($sequenceId) . $payload);
    }

    public function readResponse(bool $checkResponse = true): string
    {
        $length = $this->readPayloadLength();
        if ($length === null) {
            return '';
        }

        $response = $this->socket->readFromSocket($length);
        if ($checkResponse) {
            $this->checkResponse($response);
        }

        while ($length === self::MAX_PAYLOAD_LENGTH) {
            $length = $this->readPayloadLength();
            if ($length === null || $length === 0) {
                break;
            }

            $response .= $this->socket->readFromSocket($length);
        }

        return $response;
    }

    private function readPayloadLength(): ?int
    {
        $header = $this->socket->readFromSocket(self::HEADER_LENGTH);
        if ($header === '') {
            return null;
        }

        return BinaryDataReader::unpack('V', substr($header, 0, 3) . "\x00")[1];
    }

    private function checkResponse(string $response): void
    {
        if (!in_array(ord($response[0]), [0x00, 0x01, 0xfe], true)) {
            $errorCode = BinaryDataReader::unpack('v', substr($response, 1, 2))[1];

            throw new BinLogException(substr($response, 9), $errorCode);
        }
    }
}
