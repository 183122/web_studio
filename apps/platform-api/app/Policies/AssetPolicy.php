<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

class AssetPolicy
{
    public function delete(User $user, Asset $asset): Response
    {
        $role = $user->workspaceRole($asset->workspace_id);
        if ($role === null) {
            return Response::denyAsNotFound();
        }

        return in_array($role, Workspace::EDIT_ROLES, true) ? Response::allow() : Response::deny('สิทธิ์ของคุณเป็นแบบอ่านอย่างเดียว');
    }
}
