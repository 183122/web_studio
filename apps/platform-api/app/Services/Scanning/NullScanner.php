<?php

namespace App\Services\Scanning;

/** Used when no scanner is configured. Results are explicitly reported as "not_scanned" (never "clean"). */
class NullScanner implements MalwareScanner
{
    public function scan(string $contents): string
    {
        return 'not_scanned';
    }
}
