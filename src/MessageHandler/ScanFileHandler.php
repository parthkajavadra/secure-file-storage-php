<?php

namespace App\MessageHandler;

use App\Message\ScanFileMessage;
use App\Service\FileStorage;
use App\Service\VirusScannerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ScanFileHandler
{
    public function __construct(
        private readonly VirusScannerInterface $scanner,
        private readonly FileStorage $storage,
        private readonly LoggerInterface $accessLogger,
    ) {
    }

    /** @return array<string, string>|null */
    public function __invoke(ScanFileMessage $message): ?array
    {
        if (!$this->storage->exists($message->relativePath)) {
            return null;
        }

        $result = $this->scanner->scan($this->storage->absolutePath($message->relativePath));
        if (null !== $result) {
            $this->accessLogger->warning(sprintf(
                '[Background Scan] File ID: %d | File: %s | Result: %s',
                $message->fileId,
                $message->relativePath,
                json_encode($result, \JSON_UNESCAPED_SLASHES),
            ));
        }

        return $result;
    }
}
