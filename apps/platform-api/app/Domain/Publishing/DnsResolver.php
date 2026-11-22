<?php

namespace App\Domain\Publishing;

interface DnsResolver
{
    /** @return list<string> */
    public function txt(string $name): array;

    /** @return list<string> lowercase targets without trailing dot */
    public function cname(string $name): array;

    /** @return list<string> */
    public function a(string $name): array;
}
