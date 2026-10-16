<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * ClamAV client over clamd's TCP socket (replaces pyClamd.ClamdNetworkSocket).
 *
 * Uses the INSTREAM command, so the file is streamed to clamd and the daemon does not
 * need read access to the PHP app's filesystem (works with ClamAV in a separate container).
 */
final class ClamAvScanner implements VirusScannerInterface
{
    private const CHUNK_SIZE = 8192;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly LoggerInterface $logger,
        private readonly float $timeout = 30.0,
    ) {
    }

    public function scan(string $path): ?array
    {
        try {
            if (!$this->ping()) {
                $this->logger->error('ClamAV daemon is not responding to ping.');

                return ['error' => 'ClamAV daemon did not respond'];
            }
            $this->logger->info('ClamAV daemon is responsive.');

            $reply = $this->instream($path);
            $this->logger->debug('Scan result returned: '.$reply);

            // Replies: "stream: OK" | "stream: <signature> FOUND" | "<message> ERROR"
            if (preg_match('/^stream: OK$/', $reply)) {
                $this->logger->info('No virus detected.');

                return null;
            }
            if (preg_match('/^stream: (.+) FOUND$/', $reply, $m)) {
                $this->logger->warning(sprintf('Virus detected: %s (%s)', $m[1], basename($path)));

                return ['status' => 'FOUND', 'signature' => $m[1]];
            }

            $this->logger->error('Unexpected ClamAV reply: '.$reply);

            return ['error' => $reply];
        } catch (\Throwable $e) {
            $this->logger->error('Virus scanning failed: '.$e->getMessage(), ['exception' => $e]);

            return ['error' => $e->getMessage()];
        }
    }

    private function ping(): bool
    {
        $socket = $this->connect();
        try {
            $this->write($socket, "zPING\0");

            return 'PONG' === $this->readReply($socket);
        } finally {
            fclose($socket);
        }
    }

    private function instream(string $path): string
    {
        $file = @fopen($path, 'rb');
        if (false === $file) {
            throw new \RuntimeException(sprintf('Cannot open "%s" for scanning.', $path));
        }

        $socket = $this->connect();
        try {
            $this->write($socket, "zINSTREAM\0");
            while (!feof($file)) {
                $chunk = fread($file, self::CHUNK_SIZE);
                if (false === $chunk || '' === $chunk) {
                    break;
                }
                $this->write($socket, pack('N', \strlen($chunk)).$chunk);
            }
            $this->write($socket, pack('N', 0));

            return $this->readReply($socket);
        } finally {
            fclose($socket);
            fclose($file);
        }
    }

    /** @return resource */
    private function connect()
    {
        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $this->host, $this->port),
            $errno,
            $errstr,
            $this->timeout,
        );
        if (false === $socket) {
            throw new \RuntimeException(sprintf('Cannot connect to ClamAV at %s:%d: %s', $this->host, $this->port, $errstr));
        }
        stream_set_timeout($socket, (int) ceil($this->timeout));

        return $socket;
    }

    /** @param resource $socket */
    private function write($socket, string $data): void
    {
        $length = \strlen($data);
        $written = 0;
        while ($written < $length) {
            $n = fwrite($socket, substr($data, $written));
            if (false === $n || 0 === $n) {
                throw new \RuntimeException('Write to ClamAV socket failed (stream size limit exceeded?).');
            }
            $written += $n;
        }
    }

    /** @param resource $socket */
    private function readReply($socket): string
    {
        $buffer = '';
        while (!feof($socket)) {
            $chunk = fread($socket, 4096);
            if (false === $chunk || '' === $chunk) {
                if (stream_get_meta_data($socket)['timed_out']) {
                    throw new \RuntimeException('Timed out waiting for ClamAV.');
                }
                continue;
            }
            $buffer .= $chunk;
            if (str_contains($buffer, "\0")) {
                break;
            }
        }

        return trim($buffer, "\0 \n\r");
    }
}
