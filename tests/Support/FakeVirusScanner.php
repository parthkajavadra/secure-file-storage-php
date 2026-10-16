<?php

namespace App\Tests\Support;

use App\Service\VirusScannerInterface;

/**
 * Stand-in for ClamAV in tests: any file containing the marker below is "infected".
 * (A made-up marker rather than the EICAR string, so your own antivirus leaves the repo alone.)
 */
final class FakeVirusScanner implements VirusScannerInterface
{
    public const MARKER = 'FAKE-VIRUS-SIGNATURE-FOR-TESTS';

    public function scan(string $path): ?array
    {
        $content = (string) @file_get_contents($path);

        return str_contains($content, self::MARKER)
            ? ['status' => 'FOUND', 'signature' => 'Fake-Test-Signature']
            : null;
    }
}
