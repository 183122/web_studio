<?php

namespace App\Domain\Publishing;

/** Live DNS lookups via the system resolver. */
class SystemDnsResolver implements DnsResolver
{
    private function query(string $name, int $type): array
    {
        $records = @dns_get_record($name, $type);

        return is_array($records) ? $records : [];
    }

    public function txt(string $name): array
    {
        return array_values(array_map(fn ($r) => (string) ($r['txt'] ?? implode('', $r['entries'] ?? [])), $this->query($name, DNS_TXT)));
    }

    public function cname(string $name): array
    {
        return array_values(array_map(fn ($r) => rtrim(strtolower((string) $r['target']), '.'), $this->query($name, DNS_CNAME)));
    }

    public function a(string $name): array
    {
        return array_values(array_map(fn ($r) => (string) $r['ip'], $this->query($name, DNS_A)));
    }
}
