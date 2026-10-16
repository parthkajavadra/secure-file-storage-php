<?php

namespace App\Message;

/**
 * Background virus scan of a stored file (replaces the Celery task storage.tasks.async_scan_file).
 */
final class ScanFileMessage
{
    public function __construct(
        public readonly int $fileId,
        public readonly string $relativePath,
    ) {
    }
}
