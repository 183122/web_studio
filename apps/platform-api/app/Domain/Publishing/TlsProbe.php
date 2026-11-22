<?php

namespace App\Domain\Publishing;

interface TlsProbe
{
    /** True when https://$host presents a certificate valid for $host. */
    public function valid(string $host): bool;
}
