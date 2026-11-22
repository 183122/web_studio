<?php

namespace App\Domain\Publishing;

/** TLS handshake with peer + hostname verification against the system CA bundle. */
class SocketTlsProbe implements TlsProbe
{
    public function valid(string $host): bool
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true]]);
        $fp = @stream_socket_client("ssl://{$host}:443", $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
        if ($fp === false) {
            return false;
        }
        fclose($fp);

        return true;
    }
}
