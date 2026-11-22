<?php

declare(strict_types=1);

namespace MySQLReplication\BinLog;

use MySQLReplication\Exception\MySQLReplicationException;

class BinLogException extends MySQLReplicationException
{
    public const TLS_NOT_OFFERED_MESSAGE = 'MySQL server does not offer TLS';
    public const TLS_NOT_OFFERED_CODE = 417;
}
