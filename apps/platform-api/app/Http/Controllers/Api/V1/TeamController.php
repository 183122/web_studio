<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\AuditLogger;
use App\Services\EntitlementService;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Workspace members and e-mail invitations (seat-limited by plan). */
class TeamController extends Controller
{
    public const ROLES = ['admin', 'editor', 'developer', 'viewer'];

    public function __construct(private readonly AuditLogger $audit, private readonly EntitlementService $entitlements) {}

    public function members(Workspace $workspace): JsonResponse
    {
        $this->authorize('view', $workspace);
        $rows = WorkspaceMember::query()->where('workspace_id', $workspace->id)->get();
        $users = User::query()->whereIn('id', $rows->pluck('user_id'))->get()->keyBy('id');

        return response()->json(['data' => [
            'members' => $rows->map(fn ($m) => ['userId' => $m->user_id, 'name' => $users[$m->user_id]?->name, 'email' => $users[$m->user_id]?->email, 'role' => $m->role, 'twoFactor' => $users[$m->user_id]?->two_factor_confirmed_at !== null])->values(),
            'invitations' => DB::table('invitations')->where('workspace_id', $workspace->id)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->orderByDesc('created_at')->get()
                ->map(fn ($i) => ['id' => $i->id, 'email' => $i->email, 'role' => $i->workspace_role, 'expiresAt' => Carbon::parse($i->expires_at)->toIso8601String()]),
        ]]);
    }

    public function invite(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('admin', $workspace);
        $d = $request->validate(['email' => ['required', 'email:rfc', 'max:190'], 'role' => ['required', Rule::in(self::ROLES)]]);
        $email = mb_strtolower(trim($d['email']));
        $org = Organization::query()->with('plan')->findOrFail($workspace->organization_id);
        $existing = User::query()->where('email', $email)->value('id');
        if ($existing && WorkspaceMember::query()->where('workspace_id', $workspace->id)->where('user_id', $existing)->exists()) {
            throw ApiException::conflict('already_member', 'ผู้ใช้นี้เป็นสมาชิกอยู่แล้ว');
        }
        $token = Str::random(48);
        DB::transaction(function () use ($org, $workspace, $email, $d, $token, $request, $existing) {
            Organization::query()->whereKey($org->id)->lockForUpdate()->first();
            DB::table('invitations')->where('workspace_id', $workspace->id)->where('email', $email)->whereNull('accepted_at')->update(['revoked_at' => now()]);
            if (! ($existing && OrganizationMember::query()->where('organization_id', $org->id)->where('user_id', $existing)->exists())) {
                $this->entitlements->assertCanAddMember($org);
            }
            DB::table('invitations')->insert([
                'id' => (string) Str::ulid(), 'organization_id' => $org->id, 'workspace_id' => $workspace->id, 'email' => $email, 'workspace_role' => $d['role'],
                'token_hash' => hash('sha256', $token), 'invited_by' => $request->user()->id, 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $link = rtrim((string) config('studio.frontend_url'), '/').'/invite/'.$token;
        $inviter = $request->user()->name;
        Mail::raw("{$inviter} เชิญคุณเข้าร่วม Workspace \"{$workspace->name}\" ใน AI Studio Cloud\n\nตอบรับคำเชิญ (หมดอายุใน 7 วัน):\n{$link}\n\nหากไม่รู้จักผู้เชิญ ไม่ต้องดำเนินการใด ๆ", fn (Message $m) => $m->to($email)->subject('คำเชิญเข้าร่วม Workspace'));
        $this->audit->record('team.invited', $workspace, ['email_hash' => hash('sha256', $email), 'role' => $d['role']], $org->id, $workspace->id);

        return response()->json(['data' => ['ok' => true]], 201);
    }

    public function revokeInvitation(Workspace $workspace, string $id): JsonResponse
    {
        $this->authorize('admin', $workspace);
        DB::table('invitations')->where('workspace_id', $workspace->id)->where('id', $id)->whereNull('accepted_at')->update(['revoked_at' => now()]);

        return response()->json(['data' => ['ok' => true]]);
    }

    private function pending(string $token): object
    {
        $inv = strlen($token) === 48 ? DB::table('invitations')->where('token_hash', hash('sha256', $token))->first() : null;
        if ($inv === null || $inv->accepted_at || $inv->revoked_at || Carbon::parse($inv->expires_at)->isPast()) {
            throw new ApiException(404, 'invitation_invalid', 'คำเชิญนี้หมดอายุ ถูกยกเลิก หรือถูกใช้ไปแล้ว');
        }

        return $inv;
    }

    public function showInvitation(Request $request, string $token): JsonResponse
    {
        $inv = $this->pending($token);
        [$local, $domain] = explode('@', $inv->email) + [1 => ''];

        return response()->json(['data' => [
            'workspace' => Workspace::query()->whereKey($inv->workspace_id)->value('name'),
            'organization' => Organization::query()->whereKey($inv->organization_id)->value('name'),
            'invitedBy' => User::query()->whereKey($inv->invited_by)->value('name'),
            'role' => $inv->workspace_role,
            'email' => mb_substr($local, 0, 2).str_repeat('•', max(1, mb_strlen($local) - 2)).'@'.$domain,
            'emailMatches' => mb_strtolower($request->user()->email) === $inv->email,
        ]]);
    }

    public function acceptInvitation(Request $request, string $token): JsonResponse
    {
        $inv = $this->pending($token);
        $user = $request->user();
        if (mb_strtolower($user->email) !== $inv->email) {
            throw ApiException::unprocessable('invitation_email_mismatch', 'คำเชิญนี้ส่งถึงอีเมลอื่น กรุณาเข้าสู่ระบบด้วยบัญชีที่ได้รับเชิญ');
        }
        DB::transaction(function () use ($inv, $user) {
            if (! OrganizationMember::query()->where('organization_id', $inv->organization_id)->where('user_id', $user->id)->exists()) {
                OrganizationMember::query()->create(['organization_id' => $inv->organization_id, 'user_id' => $user->id, 'role' => 'member']);
            }
            WorkspaceMember::query()->updateOrCreate(['workspace_id' => $inv->workspace_id, 'user_id' => $user->id], ['role' => $inv->workspace_role]);
            DB::table('invitations')->where('id', $inv->id)->update(['accepted_at' => now(), 'updated_at' => now()]);
        });
        $this->audit->record('team.invitation_accepted', $user, ['workspace_id' => $inv->workspace_id], $inv->organization_id, $inv->workspace_id, $user->id);

        return response()->json(['data' => ['workspaceId' => $inv->workspace_id]]);
    }

    public function updateMember(Request $request, Workspace $workspace, string $userId): JsonResponse
    {
        $this->authorize('admin', $workspace);
        $d = $request->validate(['role' => ['required', Rule::in(self::ROLES)]]);
        $m = WorkspaceMember::query()->where('workspace_id', $workspace->id)->where('user_id', $userId)->firstOr(fn () => throw new ApiException(404, 'not_found', 'ไม่พบสมาชิก'));
        if ($m->role === 'admin' && $d['role'] !== 'admin' && WorkspaceMember::query()->where('workspace_id', $workspace->id)->where('role', 'admin')->count() <= 1) {
            throw ApiException::unprocessable('last_admin', 'ต้องมีผู้ดูแลอย่างน้อย 1 คน');
        }
        $m->forceFill(['role' => $d['role']])->save();
        $this->audit->record('team.role_changed', $workspace, ['user_id' => $userId, 'role' => $d['role']], $workspace->organization_id, $workspace->id);

        return $this->members($workspace);
    }

    public function removeMember(Request $request, Workspace $workspace, string $userId): JsonResponse
    {
        $this->authorize('admin', $workspace);
        $m = WorkspaceMember::query()->where('workspace_id', $workspace->id)->where('user_id', $userId)->firstOr(fn () => throw new ApiException(404, 'not_found', 'ไม่พบสมาชิก'));
        if ($m->role === 'admin' && WorkspaceMember::query()->where('workspace_id', $workspace->id)->where('role', 'admin')->count() <= 1) {
            throw ApiException::unprocessable('last_admin', 'ต้องมีผู้ดูแลอย่างน้อย 1 คน');
        }
        if (Organization::query()->whereKey($workspace->organization_id)->value('owner_id') === $userId) {
            throw ApiException::unprocessable('owner', 'นำเจ้าขององค์กรออกไม่ได้');
        }
        DB::transaction(function () use ($m, $workspace, $userId) {
            $m->delete();
            $stillInOrg = WorkspaceMember::query()->where('user_id', $userId)->whereIn('workspace_id', Workspace::query()->where('organization_id', $workspace->organization_id)->select('id'))->exists();
            if (! $stillInOrg) {
                OrganizationMember::query()->where('organization_id', $workspace->organization_id)->where('user_id', $userId)->delete();
            }
        });
        $this->audit->record('team.member_removed', $workspace, ['user_id' => $userId], $workspace->organization_id, $workspace->id);

        return $this->members($workspace);
    }
}
