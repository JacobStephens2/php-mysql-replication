<?php

declare(strict_types=1);

namespace MySQLReplication\Socket;

interface SocketInterface
{
    public function connectToStream(string $host, int $port): void;

    public function readFromSocket(int $length): string;

    public function writeToSocket(string $data): void;

    public function enableTls(string $peerName, string $trustAnchorFile, string $trustAnchorDirectory): void;
}
