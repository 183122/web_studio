<?php

namespace App\Services;

use App\Models\AppRecord;
use App\Models\Asset;
use App\Models\Organization;
use App\Models\Project;
use App\Support\ApiException;

/**
 * Plan entitlements (data-driven, see plans.entitlements). Phase 1 enforces project count and storage;
 * full metering/credits arrive in Phase 6.
 */
class EntitlementService
{
    public const DEFAULTS = [
        'max_projects' => 3,
        'storage_bytes' => 100 * 1024 * 1024,
        'max_workspaces' => 1,
        'max_records' => 1000,
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

    public function usage(Organization $org): array
    {
        return [
            'projects' => ['used' => Project::query()->where('organization_id', $org->id)->count(), 'limit' => $this->limit($org, 'max_projects')],
            'records' => ['used' => AppRecord::query()->whereIn('project_id', Project::query()->where('organization_id', $org->id)->select('id'))->count(), 'limit' => $this->limit($org, 'max_records')],
            'storage_bytes' => ['used' => $this->storageUsed($org), 'limit' => $this->limit($org, 'storage_bytes')],
        ];
    }
}
