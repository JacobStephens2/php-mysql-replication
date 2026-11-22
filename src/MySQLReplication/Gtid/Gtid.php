<?php

declare(strict_types=1);

namespace MySQLReplication\Gtid;

use MySQLReplication\BinaryDataReader\BinaryDataReader;

class Gtid
{
    private const SID_LENGTH = 16;
    private const INTERVAL_LENGTH = 2 * BinaryDataReader::UNSIGNED_INT64_LENGTH;

    private array $intervals = [];
    private string $sid;

    public function __construct(string $gtid)
    {
        if ((bool)preg_match(
            '/^([0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12})((?::[0-9-]+)+)$/',
            $gtid,
            $matches
        ) === false) {
            throw new GtidException(GtidException::INCORRECT_GTID_MESSAGE, GtidException::INCORRECT_GTID_CODE);
        }

        $this->sid = $matches[1];
        foreach (array_filter(explode(':', $matches[2])) as $k) {
            $this->intervals[] = explode('-', $k);
        }
        $this->sid = str_replace('-', '', $this->sid);
    }

    public function getEncoded(): string
    {
        $buffer = pack('H*', $this->sid);
        $buffer .= BinaryDataReader::pack64bit(count($this->intervals));

        foreach ($this->intervals as $interval) {
            $buffer .= BinaryDataReader::pack64bit((int)$interval[0]);
            if (count($interval) !== 1) {
                $buffer .= BinaryDataReader::pack64bit((int)$interval[1]);
            } else {
                $buffer .= BinaryDataReader::pack64bit($interval[0] + 1);
            }
        }

        return $buffer;
    }

    public function getEncodedLength(): int
    {
        return self::SID_LENGTH
            + BinaryDataReader::UNSIGNED_INT64_LENGTH
            + self::INTERVAL_LENGTH * count($this->intervals);
    }
}
