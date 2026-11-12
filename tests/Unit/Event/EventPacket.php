<?php

declare(strict_types=1);

namespace MySQLReplication\Tests\Unit\Event;

use MySQLReplication\BinLog\BinLogAuthPluginMode;
use MySQLReplication\BinLog\BinLogServerInfo;

/** Complete response payloads: status byte, 19-byte v4 header, body, optional checksum (no transport header). */
final class EventPacket
{
    public static function response(int $type, string $body = '', int $position = 120, bool $checksum = false): string
    {
        $trailer = $checksum ? "\xde\xad\xbe\xef" : '';

        return "\x00" . pack('VCVVVv', 1700000000, $type, 42, 19 + strlen($body . $trailer), $position, 1)
            . $body . $trailer;
    }

    public static function serverInfo(bool $mariaDb = false, float $revision = 10.11): BinLogServerInfo
    {
        return new BinLogServerInfo(
            10,
            $mariaDb ? '10.11-MariaDB' : '8.0.0',
            1,
            '',
            BinLogAuthPluginMode::MysqlNativePassword,
            $mariaDb ? 'MariaDB' : 'MySQL',
            $mariaDb ? $revision : 8.0
        );
    }

    public static function query(string $query): string
    {
        // Thread 123, execution time 2, schema length 3, no error or status variables, schema and SQL.
        return pack('VVCvv', 123, 2, 3, 0, 0) . "app\x00" . $query;
    }

    public static function tableMap(string $table = 'items'): string
    {
        // Six-byte table ID 7, flags, schema, table, one LONG column, no metadata, not nullable.
        return "\x07\x00\x00\x00\x00\x00\x00\x00\x03app\x00" . chr(strlen($table)) . $table
            . "\x00\x01\x03\x00\x00";
    }
}
