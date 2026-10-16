<?php

namespace App\Service;

interface VirusScannerInterface
{
    /**
     * Port of storage.utils.virus_scan.scan_file_for_virus().
     *
     * @return array<string, string>|null null when the file is clean; otherwise details, e.g.
     *                                    ['status' => 'FOUND', 'signature' => 'Eicar-Test-Signature']
     *                                    or ['error' => '...'] when scanning failed (treated as unsafe)
     */
    public function scan(string $path): ?array;
}
