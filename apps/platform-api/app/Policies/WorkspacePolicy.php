<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

/**
 * Tenant boundary: non-members always receive 404 (existence is not disclosed).
 */
class WorkspacePolicy
{
    public function view(User $user, Workspace $workspace): Response
    {
        return $user->workspaceRole($workspace->id) !== null ? Response::allow() : Response::denyAsNotFound();
    }

    public function edit(User $user, Workspace $workspace): Response
    {
        $role = $user->workspaceRole($workspace->id);
        if ($role === null) {
            return Response::denyAsNotFound();
        }

        return in_array($role, Workspace::EDIT_ROLES, true) ? Response::allow() : Response::deny('สิทธิ์ของคุณเป็นแบบอ่านอย่างเดียว');
    }

    public function admin(User $user, Workspace $workspace): Response
    {
        $role = $user->workspaceRole($workspace->id);
        if ($role === null) {
            return Response::denyAsNotFound();
        }

        return $role === 'admin' ? Response::allow() : Response::deny('ต้องเป็นผู้ดูแล Workspace');
    }
}
