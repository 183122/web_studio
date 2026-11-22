<?php

namespace App\Services\Scanning;

use Illuminate\Support\Facades\Log;

/**
 * clamd INSTREAM client (https://docs.clamav.net/manual/Usage/Scanning.html#clamd).
 * Enabled when CLAMAV_HOST is set. Not exercised by the automated suite (requires a clamd service).
 */
class ClamAvScanner implements MalwareScanner
{
    public function __construct(private readonly string $host, private readonly int $port, private readonly int $timeout) {}

    public function scan(string $contents): string
    {
        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $errstr, $this->timeout);
        if ($socket === false) {
            Log::warning('clamav.unreachable', ['error' => $errstr]);

            return 'failed';
        }
        stream_set_timeout($socket, $this->timeout);
        fwrite($socket, "zINSTREAM\0");
        foreach (str_split($contents, 8192) as $chunk) {
            fwrite($socket, pack('N', strlen($chunk)).$chunk);
        }
        fwrite($socket, pack('N', 0));
        $reply = trim((string) stream_get_contents($socket), "\0\r\n ");
        fclose($socket);

        if (str_ends_with($reply, 'OK')) {
            return 'clean';
        }
        if (str_ends_with($reply, 'FOUND')) {
            return 'infected';
        }
        Log::warning('clamav.unexpected_reply', ['reply' => mb_substr($reply, 0, 200)]);

        return 'failed';
    }
}
