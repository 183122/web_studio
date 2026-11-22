<?php

namespace App\Domain\Publishing;

/**
 * Records from a JSON file: {"txt": {"name": ["v"]}, "cname": {"name": ["t"]}, "a": {"name": ["ip"]}}.
 * For local development and E2E only (selected with STUDIO_DNS_DRIVER=static).
 */
class StaticDnsResolver implements DnsResolver
{
    public function __construct(private readonly ?string $file) {}

    private function data(): array
    {
        if (! $this->file || ! is_file($this->file)) {
            return [];
        }
        $d = json_decode((string) file_get_contents($this->file), true);

        return is_array($d) ? $d : [];
    }

    public function txt(string $name): array
    {
        return array_values($this->data()['txt'][$name] ?? []);
    }

    public function cname(string $name): array
    {
        return array_values($this->data()['cname'][$name] ?? []);
    }

    public function a(string $name): array
    {
        return array_values($this->data()['a'][$name] ?? []);
    }
}
