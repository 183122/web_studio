<?php

namespace App\Services;

use App\Domain\Ai\AiCredits;
use App\Models\AppRecord;
use App\Models\Asset;
use App\Models\Domain;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Project;
use App\Support\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Plan entitlements (data-driven, see plans.entitlements): hard limits for countable resources and
 * metered monthly limits (UsageMeter). Downgrades never delete data; checks only block new usage.
 */
class EntitlementService
{
    public const DEFAULTS = [
        'max_projects' => 3,
        'storage_bytes' => 100 * 1024 * 1024,
        'max_workspaces' => 1,
        'max_records' => 1000,
        'max_custom_domains' => 0,
        'max_members' => 2,
        'runtime_requests_monthly' => 10000,
        'build_minutes_monthly' => 30,
        'ai_credits_monthly' => 100,
    ];

    public function limit(Organization $org, string $key): int
    {
        $entitlements = $org->plan?->entitlements ?? [];

        return (int) ($entitlements[$key] ?? self::DEFAULTS[$key] ?? 0);
    }

    /** Call inside a transaction holding a lock on the organization row. */
    public function assertCanCreateProject(Organization $org): void
    {
        $limit = $this->limit($org, 'max_projects');
        $used = Project::query()->where('organization_id', $org->id)->count(); // includes archived; trashed excluded
        if ($used >= $limit) {
            throw ApiException::quota('จำนวนโปรเจกต์', $limit);
        }
    }

    /** Call inside a transaction holding a lock on the organization row. */
    public function assertCanCreateRecord(Organization $org): void
    {
        $limit = $this->limit($org, 'max_records');
        $used = AppRecord::query()->whereIn('project_id', Project::query()->where('organization_id', $org->id)->select('id'))->count();
        if ($used >= $limit) {
            throw ApiException::quota('จำนวนข้อมูล (records)', $limit);
        }
    }

    public function storageUsed(Organization $org): int
    {
        return (int) Asset::query()->where('organization_id', $org->id)->sum('size_bytes');
    }

    public function assertStorageAvailable(Organization $org, int $additionalBytes): void
    {
        $limit = $this->limit($org, 'storage_bytes');
        if ($this->storageUsed($org) + $additionalBytes > $limit) {
            throw ApiException::quota('พื้นที่จัดเก็บ (ไบต์)', $limit);
        }
    }

    public function customDomainsUsed(Organization $org): int
    {
        return Domain::query()->where('organization_id', $org->id)->where('type', 'custom')->whereNull('released_at')->count();
    }

    public function assertCanAddCustomDomain(Organization $org): void
    {
        $limit = $this->limit($org, 'max_custom_domains');
        if ($this->customDomainsUsed($org) >= $limit) {
            throw ApiException::quota('โดเมนของตัวเอง', $limit);
        }
    }

    public function membersUsed(Organization $org): int
    {
        return OrganizationMember::query()->where('organization_id', $org->id)->count()
            + DB::table('invitations')->where('organization_id', $org->id)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count();
    }

    public function assertCanAddMember(Organization $org): void
    {
        $limit = $this->limit($org, 'max_members');
        if ($this->membersUsed($org) >= $limit) {
            throw ApiException::quota('จำนวนสมาชิก', $limit);
        }
    }

    public function usage(Organization $org): array
    {
        $meter = app(UsageMeter::class);

        return [
            'custom_domains' => ['used' => $this->customDomainsUsed($org), 'limit' => $this->limit($org, 'max_custom_domains')],
            'members' => ['used' => $this->membersUsed($org), 'limit' => $this->limit($org, 'max_members')],
            'ai_credits' => ['used' => app(AiCredits::class)->used($org), 'limit' => app(AiCredits::class)->limit($org)],
            'runtime_requests' => $meter->status($org, 'runtime_requests'),
            'build_minutes' => $meter->status($org, 'build_seconds'),
            'projects' => ['used' => Project::query()->where('organization_id', $org->id)->count(), 'limit' => $this->limit($org, 'max_projects')],
            'records' => ['used' => AppRecord::query()->whereIn('project_id', Project::query()->where('organization_id', $org->id)->select('id'))->count(), 'limit' => $this->limit($org, 'max_records')],
            'storage_bytes' => ['used' => $this->storageUsed($org), 'limit' => $this->limit($org, 'storage_bytes')],
        ];
    }
}
