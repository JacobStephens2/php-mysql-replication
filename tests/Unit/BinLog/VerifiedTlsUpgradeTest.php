<?php

declare(strict_types=1);

namespace MySQLReplication\Tests\Unit\BinLog;

use MySQLReplication\BinLog\BinLogException;
use MySQLReplication\BinLog\BinLogSocketConnect;
use MySQLReplication\Config\ConfigBuilder;
use MySQLReplication\Exception\MySQLReplicationException;
use MySQLReplication\Repository\RepositoryInterface;
use MySQLReplication\Socket\Socket;
use MySQLReplication\Socket\SocketException;
use MySQLReplication\Socket\SocketInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class VerifiedTlsUpgradeTest extends TestCase
{
    private string $workDirectory = '';

    protected function tearDown(): void
    {
        if ($this->workDirectory !== '' && is_dir($this->workDirectory)) {
            $this->removeTree($this->workDirectory);
        }
    }

    #[DataProvider('matchingAnchors')]
    public function testMatchingLocalCertificateContinuesAfterTheHostCheck(
        string $layout,
        string $host,
        string $name
    ): void {
        $issued = $this->issue($name);
        $result = $this->startup(
            'success',
            $host,
            $issued['serverPem'],
            $this->anchors($layout, $issued),
            $this->greeting(true)
        );

        self::assertNull(
            $result['exception'],
            $result['stderr'] . $this->describeWrites($result['writes'])
        );
        // Protocol::SSLRequest is 32 bytes at sequence 1: flags 565781 plus CLIENT_SSL (2048),
        // max packet 0xffffff, character set 0x21, and 23 reserved zero bytes. No credentials.
        self::assertSame($this->tlsRequest(), $result['writes'][0]);
        self::assertSame($this->authenticationPacket(), $result['writes'][1]);
    }

    public static function matchingAnchors(): iterable
    {
        yield 'pem file for an IP host' => ['file', '127.0.0.1', 'IP:127.0.0.1'];
        yield 'directory for an IP host' => ['directory', '127.0.0.1', 'IP:127.0.0.1'];
        yield 'pem file and directory for an IP host' => ['both', '127.0.0.1', 'IP:127.0.0.1'];
        yield 'pem file for a DNS host' => ['file', 'localhost', 'DNS:localhost'];
    }

    public function testCertificateForAnotherNameAbortsBeforeClientAuthentication(): void
    {
        $issued = $this->issue('DNS:other.example');
        $result = $this->startup(
            'success',
            '127.0.0.1',
            $issued['serverPem'],
            ['file' => $issued['caPem'], 'directory' => ''],
            $this->greeting(true)
        );

        self::assertInstanceOf(SocketException::class, $result['exception']);
        self::assertSame(SocketException::SOCKET_TLS_FAILED_CODE, $result['exception']->getCode());
        self::assertSame([$this->tlsRequest()], $result['writes']);
    }

    public function testUntrustedIssuerAbortsBeforeClientAuthentication(): void
    {
        $issued = $this->issue('IP:127.0.0.1');
        $result = $this->startup(
            'success',
            '127.0.0.1',
            $issued['untrustedServerPem'],
            ['file' => $issued['caPem'], 'directory' => ''],
            $this->greeting(true)
        );

        self::assertInstanceOf(SocketException::class, $result['exception']);
        self::assertSame(SocketException::SOCKET_TLS_FAILED_CODE, $result['exception']->getCode());
        self::assertSame([$this->tlsRequest()], $result['writes']);
    }

    public function testMissingTlsOfferAbortsBeforeClientAuthentication(): void
    {
        $issued = $this->issue('IP:127.0.0.1');
        $result = $this->startup(
            'no-offer',
            '127.0.0.1',
            '',
            ['file' => $issued['caPem'], 'directory' => ''],
            $this->greeting(false)
        );

        self::assertInstanceOf(BinLogException::class, $result['exception']);
        self::assertSame(BinLogException::TLS_NOT_OFFERED_MESSAGE, $result['exception']->getMessage());
        self::assertSame(BinLogException::TLS_NOT_OFFERED_CODE, $result['exception']->getCode());
        self::assertSame([], $result['writes']);
    }

    public function testFailedUpgradeAbortsBeforeClientAuthentication(): void
    {
        $issued = $this->issue('IP:127.0.0.1');
        $result = $this->startup(
            'close',
            '127.0.0.1',
            '',
            ['file' => $issued['caPem'], 'directory' => ''],
            $this->greeting(true)
        );

        self::assertInstanceOf(SocketException::class, $result['exception']);
        self::assertSame(SocketException::SOCKET_TLS_FAILED_CODE, $result['exception']->getCode());
        self::assertSame([$this->tlsRequest()], $result['writes']);
    }

    public function testDnsHostIsTheTlsServerNameAndAnIpHostIsNot(): void
    {
        $dns = $this->issue('DNS:localhost');
        $dnsResult = $this->startup(
            'capture-hello',
            'localhost',
            '',
            ['file' => $dns['caPem'], 'directory' => ''],
            $this->greeting(true)
        );
        self::assertSame('localhost', $dnsResult['sni']);
        self::assertSame([$this->tlsRequest()], $dnsResult['writes']);

        $ip = $this->issue('IP:127.0.0.1');
        $ipResult = $this->startup(
            'capture-hello',
            '127.0.0.1',
            '',
            ['file' => $ip['caPem'], 'directory' => ''],
            $this->greeting(true)
        );
        self::assertSame('', $ipResult['sni']);
        self::assertSame([$this->tlsRequest()], $ipResult['writes']);
    }

    public function testConfiguredAnchorsAreTheOnlyOnesUsed(): void
    {
        $issued = $this->issue('IP:127.0.0.1');
        $script = $this->workDirectory() . '/anchor-isolation.php';
        file_put_contents($script, <<<'PHP'
<?php
declare(strict_types=1);
require getenv('AUTOLOAD');

use MySQLReplication\Socket\Socket;
use MySQLReplication\Socket\SocketException;

$base = getenv('BASE');
$autoloadOk = function (string $serverPem, string $cafile, string $capath): bool {
    $ctx = stream_context_create(['ssl' => [
        'local_cert' => $serverPem,
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
    ]]);
    $server = stream_socket_server(
        'tcp://127.0.0.1:0',
        $errno,
        $errstr,
        STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        $ctx
    );
    $address = stream_socket_get_name($server, false);
    $port = (int)substr(strrchr($address, ':'), 1);
    $pid = pcntl_fork();
    if ($pid === 0) {
        $accepted = stream_socket_accept($server, 5);
        stream_context_set_option($accepted, ['ssl' => [
            'local_cert' => $serverPem,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ]]);
        @stream_socket_enable_crypto(
            $accepted,
            true,
            STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER
        );
        exit(0);
    }
    $socket = new Socket();
    try {
        $socket->connectToStream('127.0.0.1', $port);
        $socket->enableTls('127.0.0.1', $cafile, $capath);
        $ok = true;
    } catch (SocketException) {
        $ok = false;
    }
    unset($socket);
    pcntl_waitpid($pid, $status);
    fclose($server);

    return $ok;
};

$realServer = getenv('REAL_SERVER');
$otherServer = getenv('OTHER_SERVER');
$cafile = getenv('CAFILE');
$capath = getenv('CAPATH');
$directoryTrusted = $autoloadOk($realServer, '', $capath);
$iniIssuerTrusted = $autoloadOk($otherServer, '', $capath);
$fileTrusted = $autoloadOk($realServer, $cafile, '');
$iniDirectoryTrusted = $autoloadOk($otherServer, $cafile, '');
fwrite(STDOUT, json_encode([
    'directoryTrusted' => $directoryTrusted,
    'iniIssuerTrusted' => $iniIssuerTrusted,
    'fileTrusted' => $fileTrusted,
    'iniDirectoryTrusted' => $iniDirectoryTrusted,
]));
PHP);
        $command = [
            PHP_BINARY,
            '-d',
            'openssl.cafile=' . $issued['otherCaPem'],
            '-d',
            'openssl.capath=' . $issued['otherCapath'],
            $script,
        ];
        $pipes = [];
        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, [
            'AUTOLOAD' => dirname(__DIR__, 3) . '/vendor/autoload.php',
            'BASE' => $this->workDirectory(),
            'REAL_SERVER' => $issued['serverPem'],
            'OTHER_SERVER' => $issued['untrustedServerPem'],
            'CAFILE' => $issued['caPem'],
            'CAPATH' => $issued['capath'],
        ]);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        self::assertSame(0, $exitCode, $stderr . $stdout);
        $decoded = json_decode($stdout, true);
        self::assertTrue($decoded['directoryTrusted'], $stderr);
        self::assertFalse($decoded['iniIssuerTrusted'], $stderr);
        self::assertTrue($decoded['fileTrusted'], $stderr);
        self::assertFalse($decoded['iniDirectoryTrusted'], $stderr);
    }

    /**
     * @param array{file: string, directory: string} $anchors
     * @return array{writes: list<string>, exception: ?MySQLReplicationException, stderr: string, sni: string}
     */
    private function startup(
        string $mode,
        string $host,
        string $serverPem,
        array $anchors,
        string $greeting
    ): array {
        $directory = $this->workDirectory();
        $ready = $directory . '/ready-' . bin2hex(random_bytes(3));
        $stderr = $directory . '/stderr-' . bin2hex(random_bytes(3));
        $sniFile = $directory . '/sni-' . bin2hex(random_bytes(3));
        $script = $directory . '/endpoint.php';
        if (!is_file($script)) {
            file_put_contents($script, $this->endpointScript());
        }
        $bind = $host === 'localhost' ? 'tcp://[::1]:0' : 'tcp://127.0.0.1:0';
        $environment = getenv();
        self::assertIsArray($environment);
        $environment['TLS_MODE'] = $mode;
        $environment['TLS_BIND'] = $bind;
        $environment['TLS_CERT'] = $serverPem;
        $environment['TLS_GREETING'] = base64_encode($greeting);
        $environment['TLS_READY'] = $ready;
        $environment['TLS_SNI'] = $sniFile;
        $process = proc_open([PHP_BINARY, $script], [
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', $stderr, 'w'],
        ], $pipes, null, $environment);
        self::assertIsResource($process);

        try {
            $address = $this->waitForFile($ready);
            $socket = new RecordingSocket(new Socket());
            $config = (new ConfigBuilder())
                ->withHost($host)
                ->withPort($this->portFrom($address))
                ->withUser('replica')
                ->withPassword('registration-secret')
                ->withSlaveId(1)
                ->withSlaveUuid('')
                ->withBinLogFileName('binlog.000001')
                ->withBinLogPosition('4')
                ->withTrustAnchorFile($anchors['file'])
                ->withTrustAnchorDirectory($anchors['directory'])
                ->build();
            $config->validate();
            $repository = $this->createStub(RepositoryInterface::class);
            $repository->method('getVersion')->willReturn('8.0.36');
            $repository->method('isCheckSum')->willReturn(false);
            $exception = null;
            try {
                new BinLogSocketConnect($repository, $socket, new NullLogger(), $config);
            } catch (MySQLReplicationException $caught) {
                $exception = $caught;
            }
            $writes = $socket->writes;
            unset($socket);

            return [
                'writes' => $writes,
                'exception' => $exception,
                'stderr' => is_file($stderr) ? (string)file_get_contents($stderr) : '',
                'sni' => is_file($sniFile) ? (string)file_get_contents($sniFile) : '',
            ];
        } finally {
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process);
            }
            proc_close($process);
        }
    }

    /**
     * @param array{caPem: string, otherCaPem: string, capath: string} $issued
     * @return array{file: string, directory: string}
     */
    private function anchors(string $layout, array $issued): array
    {
        return match ($layout) {
            'file' => ['file' => $issued['caPem'], 'directory' => ''],
            'directory' => ['file' => '', 'directory' => $issued['capath']],
            'both' => ['file' => $issued['otherCaPem'], 'directory' => $issued['capath']],
            default => self::fail('Unknown trust-anchor layout ' . $layout),
        };
    }

    /**
     * @return array{caPem: string, otherCaPem: string, otherCapath: string, capath: string, serverPem: string, untrustedServerPem: string}
     */
    private function issue(string $name): array
    {
        $directory = $this->workDirectory() . '/' . bin2hex(random_bytes(3));
        mkdir($directory, 0700);
        $config = $directory . '/openssl.cnf';
        file_put_contents($config, $this->opensslConfig($name));
        $authority = $this->certificateAuthority($config, 'Local authority');
        $other = $this->certificateAuthority($config, 'Other authority');
        $serverPem = $directory . '/server.pem';
        $untrustedPem = $directory . '/untrusted.pem';
        $this->writeServerCertificate($config, $name, $authority['cert'], $authority['key'], $serverPem);
        $this->writeServerCertificate($config, $name, $other['cert'], $other['key'], $untrustedPem);
        $caPem = $directory . '/ca.pem';
        $otherCaPem = $directory . '/other-ca.pem';
        file_put_contents($caPem, $authority['pem']);
        file_put_contents($otherCaPem, $other['pem']);

        return [
            'caPem' => $caPem,
            'otherCaPem' => $otherCaPem,
            'capath' => $this->hashedDirectory($caPem, $directory . '/capath'),
            'otherCapath' => $this->hashedDirectory($otherCaPem, $directory . '/other-capath'),
            'serverPem' => $serverPem,
            'untrustedServerPem' => $untrustedPem,
        ];
    }

    private function opensslConfig(string $name): string
    {
        return <<<CNF
[req]
distinguished_name = dn
prompt = no
[dn]
CN = not-the-host
[v3_ca]
basicConstraints = critical,CA:TRUE
keyUsage = critical,keyCertSign,cRLSign
subjectKeyIdentifier = hash
[server_cert]
basicConstraints = CA:FALSE
keyUsage = digitalSignature,keyEncipherment
extendedKeyUsage = serverAuth
subjectAltName = {$name}
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid,issuer
CNF;
    }

    /**
     * @return array{cert: \OpenSSLCertificate, key: \OpenSSLAsymmetricKey, pem: string}
     */
    private function certificateAuthority(string $config, string $commonName): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(
            ['commonName' => $commonName],
            $key,
            ['digest_alg' => 'sha256', 'config' => $config]
        );
        self::assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, null, $key, 2, [
            'digest_alg' => 'sha256',
            'config' => $config,
            'x509_extensions' => 'v3_ca',
        ]);
        self::assertNotFalse($cert, $this->opensslErrors());
        self::assertTrue(openssl_x509_export($cert, $pem));

        return ['cert' => $cert, 'key' => $key, 'pem' => $pem];
    }

    private function writeServerCertificate(
        string $config,
        string $name,
        \OpenSSLCertificate $issuer,
        \OpenSSLAsymmetricKey $issuerKey,
        string $path
    ): void {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(
            ['commonName' => 'not-the-host'],
            $key,
            ['digest_alg' => 'sha256', 'config' => $config]
        );
        self::assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, $issuer, $issuerKey, 2, [
            'digest_alg' => 'sha256',
            'config' => $config,
            'x509_extensions' => 'server_cert',
        ]);
        self::assertNotFalse($cert, $name . ' ' . $this->opensslErrors());
        self::assertTrue(openssl_x509_export($cert, $certificatePem));
        self::assertTrue(openssl_pkey_export($key, $keyPem));
        file_put_contents($path, $certificatePem . $keyPem);
    }

    private function hashedDirectory(string $pem, string $directory): string
    {
        mkdir($directory, 0700);
        $hash = trim((string)shell_exec(
            'openssl x509 -in ' . escapeshellarg($pem) . ' -noout -subject_hash'
        ));
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $hash);
        copy($pem, $directory . '/' . $hash . '.0');

        return $directory;
    }

    private function greeting(bool $offersTls): string
    {
        $lower = $offersTls ? "\xff\xff" : "\xff\xf7";

        return "\x0a8.0.36\x00\x01\x00\x00\x00"
            . "12345678\x00" . $lower . "\x21\x02\x00\xff\xff\x15"
            . str_repeat("\x00", 10)
            . "abcdefghijkl\x00mysql_native_password\x00";
    }

    private function tlsRequest(): string
    {
        return "\x20\x00\x00\x01\x15\xaa\x08\x00\xff\xff\xff\x00\x21" . str_repeat("\x00", 23);
    }

    private function authenticationPacket(): string
    {
        $payload = "\x15\xa2\x08\x00\xff\xff\xff\x00\x21" . str_repeat("\x00", 23)
            . "replica\x00\x14" . hex2bin('56e848b0ae78f2f8a875291af4fe86cd420c7088')
            . "mysql_native_password\x00";

        return "\x53\x00\x00\x02" . $payload;
    }

    private function endpointScript(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);

$mode = getenv('TLS_MODE');
$cert = getenv('TLS_CERT');
$greeting = base64_decode((string)getenv('TLS_GREETING'), true);
$ready = getenv('TLS_READY');
$sniFile = getenv('TLS_SNI');
$bind = getenv('TLS_BIND');
$context = stream_context_create();
if (is_string($cert) && $cert !== '') {
    stream_context_set_option($context, ['ssl' => [
        'local_cert' => $cert,
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
    ]]);
}
$server = stream_socket_server(
    (string)$bind,
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context
);
if ($server === false) {
    fwrite(STDERR, "listen failed: $errstr\n");
    exit(1);
}
file_put_contents((string)$ready, (string)stream_socket_get_name($server, false));
$client = stream_socket_accept($server, 10);
if ($client === false) {
    fwrite(STDERR, "accept failed\n");
    exit(1);
}
stream_set_timeout($client, 5);
$packet = substr(pack('V', strlen((string)$greeting)), 0, 3) . "\x00" . $greeting;
$offset = 0;
while ($offset < strlen($packet)) {
    $written = fwrite($client, substr($packet, $offset));
    if ($written === false || $written === 0) {
        break;
    }
    $offset += $written;
}
if ($mode === 'no-offer') {
    fclose($client);
    exit(0);
}
$request = read_packet($client);
if ($request === null) {
    fwrite(STDERR, "no tls request\n");
    fclose($client);
    exit(0);
}
if ($mode === 'close') {
    fclose($client);
    exit(0);
}
if ($mode === 'capture-hello') {
    file_put_contents((string)$sniFile, read_sni($client));
    fclose($client);
    exit(0);
}
stream_context_set_option($client, ['ssl' => [
    'local_cert' => $cert,
    'verify_peer' => false,
    'verify_peer_name' => false,
    'allow_self_signed' => true,
]]);
$enabled = @stream_socket_enable_crypto(
    $client,
    true,
    STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER
);
if ($enabled !== true) {
    fwrite(STDERR, "server crypto failed\n");
    fclose($client);
    exit(0);
}
for ($sequence = 1; $sequence <= 3; ++$sequence) {
    $incoming = read_packet($client);
    if ($incoming === null) {
        break;
    }
    $ok = "\x01\x00\x00" . chr($sequence) . "\x00";
    fwrite($client, $ok);
}
fclose($client);
exit(0);

function read_exact($socket, int $length): ?string
{
    $buffer = '';
    while (strlen($buffer) < $length) {
        $chunk = fread($socket, $length - strlen($buffer));
        if (!is_string($chunk) || $chunk === '') {
            return null;
        }
        $buffer .= $chunk;
    }

    return $buffer;
}

function read_packet($socket): ?string
{
    $header = read_exact($socket, 4);
    if ($header === null) {
        return null;
    }
    $length = unpack('V', substr($header, 0, 3) . "\x00")[1];
    $payload = $length === 0 ? '' : read_exact($socket, $length);
    if ($payload === null) {
        return null;
    }

    return $header . $payload;
}

function read_sni($socket): string
{
    $record = read_exact($socket, 5);
    if ($record === null || ord($record[0]) !== 22) {
        return '';
    }
    $length = unpack('n', substr($record, 3, 2))[1];
    $hello = read_exact($socket, $length);
    if ($hello === null || $hello === '' || ord($hello[0]) !== 1) {
        return '';
    }
    $offset = 38;
    if ($offset >= strlen($hello)) {
        return '';
    }
    $sessionLength = ord($hello[$offset]);
    $offset += 1 + $sessionLength;
    if ($offset + 2 > strlen($hello)) {
        return '';
    }
    $cipherLength = unpack('n', substr($hello, $offset, 2))[1];
    $offset += 2 + $cipherLength;
    if ($offset >= strlen($hello)) {
        return '';
    }
    $compressionLength = ord($hello[$offset]);
    $offset += 1 + $compressionLength;
    if ($offset + 2 > strlen($hello)) {
        return '';
    }
    $extensionsLength = unpack('n', substr($hello, $offset, 2))[1];
    $offset += 2;
    $end = min(strlen($hello), $offset + $extensionsLength);
    while ($offset + 4 <= $end) {
        $type = unpack('n', substr($hello, $offset, 2))[1];
        $extensionLength = unpack('n', substr($hello, $offset + 2, 2))[1];
        $offset += 4;
        if ($type === 0 && $extensionLength >= 5 && $offset + 5 <= strlen($hello)) {
            $nameLength = unpack('n', substr($hello, $offset + 3, 2))[1];

            return substr($hello, $offset + 5, $nameLength);
        }
        $offset += $extensionLength;
    }

    return '';
}
PHP;
    }

    private function waitForFile(string $path): string
    {
        $deadline = microtime(true) + 5;
        do {
            if (is_file($path)) {
                $contents = file_get_contents($path);
                if (is_string($contents) && $contents !== '') {
                    return $contents;
                }
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        self::fail('The local endpoint did not listen: ' . $path);
    }

    private function portFrom(string $address): int
    {
        $colon = strrpos($address, ':');
        self::assertNotFalse($colon);

        return (int)substr($address, $colon + 1);
    }

    private function workDirectory(): string
    {
        if ($this->workDirectory === '') {
            $this->workDirectory = sys_get_temp_dir() . '/replication-tls-' . bin2hex(random_bytes(4));
            mkdir($this->workDirectory, 0700);
        }

        return $this->workDirectory;
    }

    private function opensslErrors(): string
    {
        $errors = [];
        while ($error = openssl_error_string()) {
            $errors[] = $error;
        }

        return implode('; ', $errors);
    }

    /**
     * @param list<string> $writes
     */
    private function describeWrites(array $writes): string
    {
        $encoded = [];
        foreach ($writes as $write) {
            $encoded[] = bin2hex($write);
        }

        return implode("\n", $encoded);
    }

    private function removeTree(string $directory): void
    {
        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}

final class RecordingSocket implements SocketInterface
{
    /** @var list<string> */
    public array $writes = [];

    public function __construct(
        private readonly Socket $socket
    ) {
    }

    public function connectToStream(string $host, int $port): void
    {
        $this->socket->connectToStream($host, $port);
    }

    public function readFromSocket(int $length): string
    {
        return $this->socket->readFromSocket($length);
    }

    public function writeToSocket(string $data): void
    {
        $this->writes[] = $data;
        $this->socket->writeToSocket($data);
    }

    public function enableTls(string $peerName, string $trustAnchorFile, string $trustAnchorDirectory): void
    {
        $this->socket->enableTls($peerName, $trustAnchorFile, $trustAnchorDirectory);
    }
}
