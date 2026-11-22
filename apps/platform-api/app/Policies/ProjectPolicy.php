<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\Response;

class ProjectPolicy
{
    public function view(User $user, Project $project): Response
    {
        return $user->workspaceRole($project->workspace_id) !== null ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, Project $project): Response
    {
        $role = $user->workspaceRole($project->workspace_id);
        if ($role === null) {
            return Response::denyAsNotFound();
        }
        if (! in_array($role, Workspace::EDIT_ROLES, true)) {
            return Response::deny('สิทธิ์ของคุณเป็นแบบอ่านอย่างเดียว');
        }
        if ($project->status === 'archived') {
            return Response::deny('โปรเจกต์ถูกเก็บถาวร กรุณานำออกจากที่เก็บถาวรก่อนแก้ไข');
        }

        return Response::allow();
    }

    /** Archive / unarchive / favorite-independent management actions. */
    public function manage(User $user, Project $project): Response
    {
        $role = $user->workspaceRole($project->workspace_id);
        if ($role === null) {
            return Response::denyAsNotFound();
        }

        return in_array($role, Workspace::EDIT_ROLES, true) ? Response::allow() : Response::deny('สิทธิ์ของคุณเป็นแบบอ่านอย่างเดียว');
    }

    public function delete(User $user, Project $project): Response
    {
        $role = $user->workspaceRole($project->workspace_id);
        if ($role === null) {
            return Response::denyAsNotFound();
        }

        return $role === 'admin' ? Response::allow() : Response::deny('เฉพาะผู้ดูแล Workspace เท่านั้นที่ลบโปรเจกต์ได้');
    }
}
