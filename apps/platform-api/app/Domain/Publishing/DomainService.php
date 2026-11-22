<?php

namespace App\Domain\Publishing;

use App\Models\Domain;
use App\Models\Organization;
use App\Models\Project;
use App\Services\AuditLogger;
use App\Services\EntitlementService;
use App\Support\ApiException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Platform subdomains and verified custom domains.
 * - A hostname is bound to one project at a time (unique `active_hostname`).
 * - Released subdomains cool down before another organisation may claim them (takeover protection).
 * - Custom domains need a TXT ownership record AND routing to the edge AND a valid certificate before
 *   they serve; ownership is re-proven whenever a domain is added again.
 */
class DomainService
{
    public function __construct(
        private readonly DnsResolver $dns,
        private readonly TlsProbe $tls,
        private readonly AuditLogger $audit,
    ) {}

    public function subdomainOf(Project $project): ?Domain
    {
        return Domain::query()->where('project_id', $project->id)->where('type', 'subdomain')->whereNull('released_at')->first();
    }

    public function reserveSubdomain(Project $project, string $slug): Domain
    {
        $slug = strtolower(trim($slug));
        if (! Hostnames::validSlug($slug)) {
            throw ApiException::unprocessable('invalid_slug', 'ชื่อโดเมนย่อยใช้ได้เฉพาะ a-z, 0-9 และ - (3–40 ตัว ไม่ขึ้นต้น/ลงท้ายด้วย -)');
        }
        if (in_array($slug, config('studio.publishing.reserved_slugs'), true)) {
            throw ApiException::unprocessable('reserved_slug', 'ชื่อนี้สงวนไว้สำหรับระบบ');
        }
        $host = Hostnames::subdomainHost($slug);
        $current = $this->subdomainOf($project);
        if ($current?->hostname === $host) {
            return $current;
        }
        // Cooldown: a released hostname may only be reclaimed by the same organisation until it expires.
        $recent = Domain::query()->where('hostname', $host)->whereNotNull('released_at')
            ->where('released_at', '>', now()->subDays((int) config('studio.publishing.slug_cooldown_days')))
            ->where('organization_id', '!=', $project->organization_id)->exists();
        if ($recent) {
            throw ApiException::conflict('slug_cooling_down', 'ชื่อนี้เพิ่งถูกปล่อยคืนและยังไม่เปิดให้ใช้ใหม่');
        }

        $domain = DB::transaction(function () use ($project, $host, $current) {
            if ($current) {
                $this->release($current);
            }
            try {
                return Domain::create([
                    'organization_id' => $project->organization_id,
                    'project_id' => $project->id,
                    'hostname' => $host,
                    'active_hostname' => $host,
                    'type' => 'subdomain',
                    'status' => 'active',
                    'verified_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw ApiException::conflict('slug_taken', 'ชื่อนี้ถูกใช้แล้ว กรุณาเลือกชื่ออื่น');
            }
        });
        $this->audit->record('domain.subdomain_reserved', $project, ['hostname' => $host]);

        return $domain;
    }

    public function addCustom(Project $project, string $input): Domain
    {
        $host = Hostnames::normalize($input);
        if ($host === null) {
            throw ApiException::unprocessable('invalid_hostname', 'รูปแบบโดเมนไม่ถูกต้อง เช่น www.example.com');
        }
        if (Hostnames::isUnderBaseDomain($host) || Hostnames::isPlatformHost($host)) {
            throw ApiException::unprocessable('invalid_hostname', 'โดเมนนี้เป็นของแพลตฟอร์ม ใช้ตั้งค่าโดเมนย่อยแทน');
        }
        if (Domain::query()->where('project_id', $project->id)->where('type', 'custom')->whereNull('released_at')->count() >= 5) {
            throw ApiException::unprocessable('too_many_domains', 'เพิ่มโดเมนได้สูงสุด 5 โดเมนต่อโปรเจกต์');
        }
        app(EntitlementService::class)->assertCanAddCustomDomain(Organization::query()->with('plan')->findOrFail($project->organization_id));
        try {
            $domain = Domain::create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'hostname' => $host,
                'active_hostname' => $host,
                'type' => 'custom',
                'status' => 'pending_verification',
                'verification_token' => 'studio-verify='.Str::lower(Str::random(32)),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ApiException::conflict('hostname_taken', 'โดเมนนี้ถูกผูกกับโปรเจกต์อื่นอยู่ ต้องนำออกจากโปรเจกต์เดิมก่อน');
        }
        $this->audit->record('domain.custom_added', $project, ['hostname' => $host]);

        return $domain;
    }

    /** DNS + TLS instructions shown to the user (apex and subdomains differ). */
    public static function instructions(Domain $domain): array
    {
        $isApex = substr_count($domain->hostname, '.') === 1;
        $records = [[
            'type' => 'TXT', 'name' => "_studio-verify.{$domain->hostname}", 'value' => $domain->verification_token,
            'purpose' => 'ยืนยันความเป็นเจ้าของโดเมน',
        ]];
        if ($isApex) {
            $records[] = ['type' => 'ALIAS/ANAME (หรือ CNAME flattening)', 'name' => $domain->hostname, 'value' => config('studio.publishing.cname_target'),
                'purpose' => 'ชี้โดเมนหลักมาที่แพลตฟอร์ม — ผู้ให้บริการ DNS บางรายไม่รองรับ ให้ใช้ www.'.$domain->hostname.' แทน'];
        } else {
            $records[] = ['type' => 'CNAME', 'name' => $domain->hostname, 'value' => config('studio.publishing.cname_target'), 'purpose' => 'ชี้โดเมนมาที่แพลตฟอร์ม'];
        }

        return ['apex' => $isApex, 'records' => $records, 'note' => 'การเปลี่ยน DNS อาจใช้เวลาตั้งแต่ไม่กี่นาทีถึง 48 ชั่วโมง'];
    }

    /** Re-runs ownership, routing and certificate checks; updates status. */
    public function check(Domain $domain): Domain
    {
        if ($domain->type !== 'custom' || $domain->released_at) {
            return $domain;
        }
        $host = $domain->hostname;
        $txt = $this->dns->txt("_studio-verify.{$host}");
        $ownership = in_array($domain->verification_token, array_map('trim', $txt), true);
        $target = config('studio.publishing.cname_target');
        $cnames = $this->dns->cname($host);
        $edgeIps = $this->dns->a($target);
        $hostIps = $this->dns->a($host);
        $routing = in_array($target, $cnames, true) || ($edgeIps !== [] && array_intersect($hostIps, $edgeIps) !== []);
        $tls = $ownership && $routing && $this->tls->valid($host);

        $checks = [
            'ownership' => ['ok' => $ownership, 'message' => $ownership ? 'พบระเบียน TXT ถูกต้อง' : ($txt === [] ? 'ยังไม่พบระเบียน TXT _studio-verify' : 'ค่า TXT ไม่ตรงกับที่กำหนด')],
            'routing' => ['ok' => $routing, 'message' => $routing ? 'โดเมนชี้มาที่แพลตฟอร์มแล้ว' : ($cnames === [] && $hostIps === [] ? 'ยังไม่พบ CNAME/ALIAS' : 'โดเมนชี้ไปที่อื่น ('.implode(', ', $cnames ?: $hostIps).')')],
            'certificate' => ['ok' => $tls, 'message' => $tls ? 'ใบรับรอง HTTPS ใช้งานได้' : ($ownership && $routing ? 'กำลังออกใบรับรอง HTTPS (อาจใช้เวลาหลายนาที)' : 'รอการยืนยันโดเมนก่อน')],
        ];
        $status = match (true) {
            $ownership && $routing && $tls => 'active',
            $ownership && $routing => 'verified',
            default => 'pending_verification',
        };
        $wasActive = $domain->status === 'active';
        $domain->fill([
            'checks' => $checks,
            'status' => $status,
            'last_checked_at' => now(),
            'verified_at' => $ownership && $routing ? ($domain->verified_at ?? now()) : null,
            'tls_active_at' => $tls ? ($domain->tls_active_at ?? now()) : null,
        ])->save();
        if ($status === 'active' && ! $wasActive) {
            $this->audit->record('domain.activated', $domain, ['hostname' => $host], $domain->organization_id);
        }

        return $domain;
    }

    public function release(Domain $domain): void
    {
        $domain->fill(['released_at' => now(), 'status' => 'released', 'active_hostname' => null])->save();
        $this->audit->record('domain.released', $domain, ['hostname' => $domain->hostname], $domain->organization_id);
    }

    /**
     * Edge authorisation for on-demand TLS (e.g. Caddy `on_demand_tls { ask … }`): only hostnames
     * we serve may obtain certificates.
     */
    public function mayIssueCertificate(string $host): bool
    {
        $host = Hostnames::normalize($host);
        if ($host === null) {
            return false;
        }
        $domain = Domain::query()->where('active_hostname', $host)->first();
        if ($domain === null) {
            return false;
        }

        return $domain->type === 'subdomain' ? $domain->status === 'active' : in_array($domain->status, ['verified', 'active'], true);
    }
}
