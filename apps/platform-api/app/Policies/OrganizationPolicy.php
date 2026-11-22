<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class OrganizationPolicy
{
    public const BILLING_ROLES = ['owner', 'admin', 'billing_admin'];

    public const ADMIN_ROLES = ['owner', 'admin'];

    private static function role(User $user, Organization $org): ?string
    {
        return OrganizationMember::query()->where('organization_id', $org->id)->where('user_id', $user->id)->value('role');
    }

    public function view(User $user, Organization $org): Response
    {
        return self::role($user, $org) !== null ? Response::allow() : Response::denyAsNotFound();
    }

    public function billing(User $user, Organization $org): Response
    {
        $role = self::role($user, $org);
        if ($role === null) {
            return Response::denyAsNotFound();
        }

        return in_array($role, self::BILLING_ROLES, true) ? Response::allow() : Response::deny('เฉพาะเจ้าของ ผู้ดูแล หรือผู้ดูแลการเงินขององค์กรเท่านั้น');
    }

    public function admin(User $user, Organization $org): Response
    {
        $role = self::role($user, $org);
        if ($role === null) {
            return Response::denyAsNotFound();
        }

        return in_array($role, self::ADMIN_ROLES, true) ? Response::allow() : Response::deny('เฉพาะเจ้าของหรือผู้ดูแลองค์กรเท่านั้น');
    }
}
