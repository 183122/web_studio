<?php

namespace App\Domain\Publishing;

/** Local development only (STUDIO_TLS_CHECK=assume): certificates are not checked. */
class AssumeTlsProbe implements TlsProbe
{
    public function valid(string $host): bool
    {
        return true;
    }
}
