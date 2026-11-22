<?php

declare(strict_types=1);

namespace MySQLReplication\BinLog;

use MySQLReplication\BinaryDataReader\BinaryDataReader;
use MySQLReplication\Config\Config;
use MySQLReplication\Gtid\GtidCollection;
use MySQLReplication\Repository\RepositoryInterface;
use MySQLReplication\Socket\SocketInterface;
use Psr\Log\LoggerInterface;

class BinLogSocketConnect
{
    private const COM_QUERY = 0x03;
    private const COM_BINLOG_DUMP = 0x12;
    private const COM_REGISTER_SLAVE = 0x15;
    private const COM_BINLOG_DUMP_GTID = 0x1e;
    private const AUTH_SWITCH_PACKET = 254;
    private const AUTH_MAX_PACKET_SIZE = 0xffffff;

    private readonly BinLogPacketStream $packetStream;
    private bool $checkSum = false;
    private BinLogCurrent $binLogCurrent;
    private BinLogServerInfo $binLogServerInfo;

    public function __construct(
        private readonly RepositoryInterface $repository,
        SocketInterface $socket,
        private readonly LoggerInterface $logger,
        private readonly Config $config
    ) {
        $this->binLogCurrent = new BinLogCurrent();
        $this->packetStream = new BinLogPacketStream($socket);

        $socket->connectToStream($config->host, $config->port);

        $this->logger->debug('Connected to ' . $config->host . ':' . $config->port);

        $this->binLogServerInfo = BinLogServerInfo::make(
            $this->getResponse(false),
            $this->repository->getVersion()
        );

        $this->logger->debug(
            'Server version name: ' . $this->binLogServerInfo->versionName . ', revision: ' . $this->binLogServerInfo->versionRevision
        );


        $this->authenticate($this->binLogServerInfo->authPlugin);
        $this->getBinlogStream();
    }

    public function getBinLogServerInfo(): BinLogServerInfo
    {
        return $this->binLogServerInfo;
    }

    public function getResponse(bool $checkResponse = true): string
    {
        return $this->packetStream->readResponse($checkResponse);
    }

    public function getBinLogCurrent(): BinLogCurrent
    {
        return $this->binLogCurrent;
    }

    public function getCheckSum(): bool
    {
        return $this->checkSum;
    }

    private function authenticate(BinLogAuthPluginMode $authPlugin): void
    {
        $this->logger->debug(
            'Trying to authenticate user: ' . $this->config->user . ' using ' . $authPlugin->value . ' default plugin'
        );

        $data = pack('L', self::getCapabilities());
        $data .= pack('L', self::AUTH_MAX_PACKET_SIZE);
        $data .= chr(33);
        $data .= str_repeat(chr(0), 23);
        $data .= $this->config->user . chr(0);
        $auth = $this->getAuthData($authPlugin, $this->binLogServerInfo->salt);
        $data .= chr(strlen($auth)) . $auth;
        $data .= $authPlugin->value . chr(0);

        $this->packetStream->writePayload($data, 1);
        $response = $this->getResponse();

        // Check for AUTH_SWITCH_PACKET
        if (isset($response[0]) && ord($response[0]) === self::AUTH_SWITCH_PACKET) {
            $this->switchAuth($response);
        }

        $this->logger->debug('User authenticated');
    }

    private function getAuthData(?BinLogAuthPluginMode $authPlugin, string $salt): string
    {
        if ($authPlugin === BinLogAuthPluginMode::MysqlNativePassword) {
            return $this->authenticateMysqlNativePasswordPlugin($salt);
        }

        if ($authPlugin === BinLogAuthPluginMode::CachingSha2Password) {
            return $this->authenticateCachingSha2PasswordPlugin($salt);
        }

        return '';
    }

    private function authenticateCachingSha2PasswordPlugin(string $salt): string
    {
        $hash1 = hash('sha256', $this->config->password, true);
        $hash2 = hash('sha256', $hash1, true);
        $hash3 = hash('sha256', $hash2 . $salt, true);
        return $hash1 ^ $hash3;
    }

    private function authenticateMysqlNativePasswordPlugin(string $salt): string
    {
        $hash1 = sha1($this->config->password, true);
        $hash2 = sha1($salt . sha1(sha1($this->config->password, true), true), true);
        return $hash1 ^ $hash2;
    }

    /**
     * https://dev.mysql.com/doc/dev/mysql-server/latest/group__group__cs__capabilities__flags.html
     * https://github.com/siddontang/mixer/blob/master/doc/protocol.txt
     */
    private static function getCapabilities(): int
    {
        $noSchema = 1 << 4;
        $longPassword = 1;
        $longFlag = 1 << 2;
        $transactions = 1 << 13;
        $secureConnection = 1 << 15;
        $protocol41 = 1 << 9;
        $authPlugin = 1 << 19;

        return $longPassword | $longFlag | $transactions | $protocol41 | $secureConnection | $noSchema | $authPlugin;
    }

    private function getBinlogStream(): void
    {
        $this->checkSum = $this->repository->isCheckSum();
        if ($this->checkSum) {
            $this->executeSQL('SET @master_binlog_checksum = @@global.binlog_checksum');
        }


        if ($this->config->heartbeatPeriod > 0.00) {
            // master_heartbeat_period is in nanoseconds
            if (version_compare($this->repository->getVersion(), '8.4.0') >= 0) {
                $this->executeSQL('SET @source_heartbeat_period = ' . $this->config->heartbeatPeriod * 1000000000);
            } else {
                $this->executeSQL('SET @master_heartbeat_period = ' . $this->config->heartbeatPeriod * 1000000000);
            }

            $this->logger->debug('Heartbeat period set to ' . $this->config->heartbeatPeriod . ' seconds');
        }

        if ($this->config->slaveUuid !== '') {
            $this->executeSQL(
                'SET @slave_uuid = \'' . $this->config->slaveUuid . '\', @replica_uuid = \'' . $this->config->slaveUuid . '\''
            );

            $this->logger->debug('Salve uuid set to ' . $this->config->slaveUuid);
        }

        $this->registerSlave();

        if ($this->config->mariaDbGtid !== '') {
            $this->setBinLogDumpMariaGtid();
        }
        if ($this->config->gtid !== '') {
            $this->setBinLogDumpGtid();
        } else {
            $this->setBinLogDump();
        }
    }

    private function executeSQL(string $sql): void
    {
        $this->packetStream->writePayload(chr(self::COM_QUERY) . $sql);
        $this->getResponse();
    }

    /**
     * @see https://dev.mysql.com/doc/internals/en/com-register-slave.html
     */
    private function registerSlave(): void
    {
        $host = (string)gethostname();
        $hostLength = strlen($host);
        $userLength = strlen($this->config->user);

        $data = chr(self::COM_REGISTER_SLAVE);
        $data .= pack('V', $this->config->slaveId);
        $data .= pack('C', $hostLength);
        $data .= $host;
        $data .= pack('C', $userLength);
        $data .= $this->config->user;
        $data .= chr(0); // Registration does not need the authentication password.
        $data .= pack('v', $this->config->port);
        $data .= pack('V', 0);
        $data .= pack('V', 0);

        $this->packetStream->writePayload($data);
        $this->getResponse();

        $this->logger->debug('Slave registered with id ' . $this->config->slaveId);
    }

    private function setBinLogDumpMariaGtid(): void
    {
        $this->executeSQL('SET @mariadb_slave_capability = 4');
        $this->executeSQL('SET @slave_connect_state = \'' . $this->config->mariaDbGtid . '\'');
        $this->executeSQL('SET @slave_gtid_strict_mode = 0');
        $this->executeSQL('SET @slave_gtid_ignore_duplicates = 0');

        $this->binLogCurrent->setMariaDbGtid($this->config->mariaDbGtid);

        $this->logger->debug('Set Maria GTID to start from: ' . $this->config->mariaDbGtid);
    }

    private function setBinLogDumpGtid(): void
    {
        $encodedGtidSet = GtidCollection::makeCollectionFromString($this->config->gtid)->getEncoded();
        $encodedGtidSetLength = strlen($encodedGtidSet);

        $data = chr(self::COM_BINLOG_DUMP_GTID);
        $data .= pack('S', 0);
        $data .= pack('I', $this->config->slaveId);
        $data .= pack('I', 3);
        $data .= chr(0);
        $data .= chr(0);
        $data .= chr(0);
        $data .= BinaryDataReader::pack64bit(4);
        $data .= pack('V', $encodedGtidSetLength);
        $data .= $encodedGtidSet;

        $this->packetStream->writePayload($data);
        $this->getResponse();

        $this->binLogCurrent->setGtid($this->config->gtid);

        $this->logger->debug('Set GTID to start from: ' . $this->config->gtid);
    }

    /**
     * 1              [12] COM_BINLOG_DUMP
     * 4              binlog-pos
     * 2              flags
     * 4              server-id
     * string[EOF]    binlog-filename
     */
    private function setBinLogDump(): void
    {
        $binFilePos = $this->config->binLogPosition;
        $binFileName = $this->config->binLogFileName;
        // if not set start from newest binlog
        if ($binFilePos === '' && $binFileName === '') {
            $masterStatusDTO = $this->repository->getMasterStatus();
            $binFilePos = $masterStatusDTO->position;
            $binFileName = $masterStatusDTO->file;
        }

        $data = chr(self::COM_BINLOG_DUMP);
        $data .= pack('I', $binFilePos);
        $data .= pack('v', 0);
        $data .= pack('I', $this->config->slaveId);
        $data .= $binFileName;

        $this->packetStream->writePayload($data);
        $this->getResponse();

        $this->binLogCurrent->setBinLogPosition($binFilePos);
        $this->binLogCurrent->setBinFileName($binFileName);

        $this->logger->debug('Set binlog to start from: ' . $binFileName . ':' . $binFilePos);
    }

    private function switchAuth(string $response): void
    {
        // skip AUTH_SWITCH_PACKET byte
        $offset = 1;
        $authPluginSwitched = BinLogAuthPluginMode::make(BinaryDataReader::decodeNullLength($response, $offset));
        $salt = BinaryDataReader::decodeNullLength($response, $offset);
        $auth = $this->getAuthData($authPluginSwitched, $salt);

        $this->logger->debug('Auth switch packet received, switching to ' . $authPluginSwitched->value);

        $this->packetStream->writePayload($auth, 3);
    }
}
