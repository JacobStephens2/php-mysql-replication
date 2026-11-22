<?php

declare(strict_types=1);

namespace MySQLReplication\Socket;

use Socket as NativeSocket;

class Socket implements SocketInterface
{
    private const TLS_CLIENT = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

    /** @var resource|null */
    private $socket = null;

    public function __destruct()
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    public function connectToStream(string $host, int $port): void
    {
        $errno = 0;
        $errstr = '';
        $socket = stream_socket_client(
            $this->streamAddress($host, $port),
            $errno,
            $errstr,
            null,
            STREAM_CLIENT_CONNECT
        );
        if ($socket === false) {
            throw new SocketException(
                SocketException::SOCKET_UNABLE_TO_CREATE_MESSAGE . $errstr,
                $errno !== 0 ? $errno : SocketException::SOCKET_UNABLE_TO_CREATE_CODE
            );
        }

        $this->socket = $socket;
        stream_set_blocking($socket, true);
        // A binlog read waits for the next event. The default stream timeout must not apply.
        stream_set_timeout($socket, -1);
        $imported = socket_import_stream($socket);
        if ($imported instanceof NativeSocket) {
            socket_set_option($imported, SOL_SOCKET, SO_KEEPALIVE, 1);
        }
    }

    public function readFromSocket(int $length): string
    {
        if ($length === 0) {
            return '';
        }

        $buffer = '';
        while (strlen($buffer) < $length) {
            $remaining = $length - strlen($buffer);
            if ($remaining < 1) {
                throw new SocketException(
                    SocketException::SOCKET_DISCONNECTED_MESSAGE,
                    SocketException::SOCKET_DISCONNECTED_CODE
                );
            }
            $chunk = fread($this->openSocket(), $remaining);
            if (!is_string($chunk) || $chunk === '') {
                throw new SocketException(
                    SocketException::SOCKET_DISCONNECTED_MESSAGE,
                    SocketException::SOCKET_DISCONNECTED_CODE
                );
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    public function writeToSocket(string $data): void
    {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $result = fwrite($this->openSocket(), substr($data, $written));
            if ($result === false || $result === 0) {
                throw new SocketException(
                    SocketException::SOCKET_UNABLE_TO_WRITE_MESSAGE . $this->streamError(),
                    SocketException::SOCKET_UNABLE_TO_WRITE_CODE
                );
            }
            $written += $result;
        }
    }

    public function enableTls(string $peerName, string $trustAnchorFile, string $trustAnchorDirectory): void
    {
        if (!extension_loaded('openssl')) {
            throw new SocketException(
                SocketException::SOCKET_TLS_FAILED_MESSAGE . 'the OpenSSL extension is required',
                SocketException::SOCKET_TLS_FAILED_CODE
            );
        }

        // With no configured anchor, omit cafile and capath so PHP uses openssl.cafile, openssl.capath,
        // or the system trust store. The host check still runs. An empty stand-in is only for a
        // configured anchor, so that anchor is not combined with the runtime store.
        if ($trustAnchorFile === '' && $trustAnchorDirectory === '') {
            $this->upgradeSocket($peerName, null, null);

            return;
        }

        $temporaryDirectory = null;
        $temporaryFile = null;
        $cafile = $trustAnchorFile !== ''
            ? $trustAnchorFile
            : ($this->firstCertificate($trustAnchorDirectory) ?? $this->blankTrustAnchor($temporaryFile));
        $capath = $trustAnchorDirectory;

        try {
            if ($capath === '') {
                $temporaryDirectory = sys_get_temp_dir() . '/php-mysql-replication-trust-' . bin2hex(random_bytes(8));
                if (!mkdir($temporaryDirectory, 0700)) {
                    throw new SocketException(
                        SocketException::SOCKET_TLS_FAILED_MESSAGE . 'could not prepare the trust anchor directory',
                        SocketException::SOCKET_TLS_FAILED_CODE
                    );
                }
                $capath = $temporaryDirectory;
            }
            $this->upgradeSocket($peerName, $cafile, $capath);
        } finally {
            if ($temporaryDirectory !== null && is_dir($temporaryDirectory)) {
                rmdir($temporaryDirectory);
            }
            if ($temporaryFile !== null && is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
    }

    private function upgradeSocket(string $peerName, ?string $cafile, ?string $capath): void
    {
        $isIp = filter_var($peerName, FILTER_VALIDATE_IP) !== false;
        $ssl = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $peerName,
            'SNI_enabled' => !$isIp,
        ];
        if ($cafile !== null) {
            $ssl['cafile'] = $cafile;
        }
        if ($capath !== null) {
            $ssl['capath'] = $capath;
        }
        $configured = stream_context_set_option($this->openSocket(), [
            'ssl' => $ssl,
        ]);
        if ($configured !== true) {
            throw new SocketException(
                SocketException::SOCKET_TLS_FAILED_MESSAGE . 'could not set the trust anchors',
                SocketException::SOCKET_TLS_FAILED_CODE
            );
        }

        while (openssl_error_string() !== false) {
        }
        $enabled = @stream_socket_enable_crypto($this->openSocket(), true, self::TLS_CLIENT);
        stream_set_timeout($this->openSocket(), -1);
        if ($enabled !== true) {
            $error = error_get_last();
            $detail = is_array($error) ? $error['message'] : 'certificate check failed';
            throw new SocketException(
                SocketException::SOCKET_TLS_FAILED_MESSAGE . $detail,
                SocketException::SOCKET_TLS_FAILED_CODE
            );
        }
    }

    private function firstCertificate(string $directory): ?string
    {
        if ($directory === '' || !is_dir($directory)) {
            return null;
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return null;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (!is_file($path)) {
                continue;
            }
            $contents = file_get_contents($path);
            if (is_string($contents) && str_contains($contents, '-----BEGIN CERTIFICATE-----')) {
                return $path;
            }
        }

        return null;
    }

    private function blankTrustAnchor(?string &$path): string
    {
        $created = tempnam(sys_get_temp_dir(), 'trust');
        if ($created === false) {
            throw new SocketException(
                SocketException::SOCKET_TLS_FAILED_MESSAGE . 'could not prepare the trust anchor file',
                SocketException::SOCKET_TLS_FAILED_CODE
            );
        }
        file_put_contents($created, "# no trust anchors\n");
        chmod($created, 0600);
        $path = $created;

        return $created;
    }

    /** @return resource */
    private function openSocket()
    {
        if (!is_resource($this->socket)) {
            throw new SocketException(
                SocketException::SOCKET_DISCONNECTED_MESSAGE,
                SocketException::SOCKET_DISCONNECTED_CODE
            );
        }

        return $this->socket;
    }

    private function streamAddress(string $host, int $port): string
    {
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }

        return 'tcp://' . $host . ':' . $port;
    }

    private function streamError(): string
    {
        $error = error_get_last();

        return is_array($error) ? $error['message'] : '';
    }
}
