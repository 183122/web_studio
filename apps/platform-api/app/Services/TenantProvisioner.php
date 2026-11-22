<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Creates the organization + default workspace for a newly registered user. */
class TenantProvisioner
{
    public function provision(User $user, ?string $organizationName = null): Organization
    {
        return DB::transaction(function () use ($user, $organizationName) {
            $name = $organizationName ?: "พื้นที่ของ {$user->name}";
            $org = Organization::create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'owner_id' => $user->id,
                'plan_id' => Plan::query()->where('code', config('studio.default_plan'))->value('id'),
            ]);
            $org->members()->attach($user->id, ['role' => 'owner']);

            $workspace = Workspace::create([
                'organization_id' => $org->id,
                'name' => 'Workspace หลัก',
                'slug' => 'main',
            ]);
            $workspace->members()->attach($user->id, ['role' => 'admin']);

            return $org;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'org';
        $base = Str::limit($base, 60, '');

        return $base.'-'.Str::lower(Str::random(6));
    }
}
