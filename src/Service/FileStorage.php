<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stores uploads under STORAGE_DIR/user_files/ (Django: FileField(upload_to="user_files/")).
 *
 * The directory is outside public/, so files can only be fetched through the
 * access-controlled download endpoints — never by guessing a URL.
 */
final class FileStorage
{
    private const SUBDIR = 'user_files';

    public function __construct(private readonly string $storageDir)
    {
    }

    /** Moves the upload into storage and returns its relative path, e.g. "user_files/report.pdf". */
    public function store(UploadedFile $upload): string
    {
        $dir = $this->storageDir.'/'.self::SUBDIR;
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create storage directory "%s".', $dir));
        }

        $name = self::safeName($upload->getClientOriginalName());
        // Like Django's storage: keep the original name, add a random suffix on collision.
        while (file_exists($dir.'/'.$name)) {
            $ext = pathinfo($name, \PATHINFO_EXTENSION);
            $stem = pathinfo($name, \PATHINFO_FILENAME);
            $stem = preg_replace('/_[0-9a-f]{7}$/', '', $stem);
            $name = $stem.'_'.substr(bin2hex(random_bytes(4)), 0, 7).('' !== $ext ? '.'.$ext : '');
        }

        $upload->move($dir, $name);

        return self::SUBDIR.'/'.$name;
    }

    /** Absolute path for a stored relative path; refuses anything that escapes the storage directory. */
    public function absolutePath(string $relativePath): string
    {
        if (str_contains($relativePath, '..') || str_starts_with($relativePath, '/') || str_contains($relativePath, "\0")) {
            throw new \InvalidArgumentException('Invalid stored file path.');
        }

        return $this->storageDir.'/'.$relativePath;
    }

    public function exists(string $relativePath): bool
    {
        return is_file($this->absolutePath($relativePath));
    }

    /** Keeps letters, digits, dot, dash and underscore; strips any directory part. */
    public static function safeName(string $clientName): string
    {
        $name = basename(str_replace('\\', '/', $clientName));
        $name = preg_replace('/\s+/', '_', $name);
        $name = preg_replace('/[^A-Za-z0-9._-]/', '', $name);
        $name = ltrim($name, '.');
        if ('' === $name) {
            $name = 'file';
        }
        if (\strlen($name) > 100) {
            $ext = pathinfo($name, \PATHINFO_EXTENSION);
            $ext = '' !== $ext ? '.'.substr($ext, 0, 10) : '';
            $name = substr(pathinfo($name, \PATHINFO_FILENAME), 0, 100 - \strlen($ext)).$ext;
        }

        return $name;
    }
}
