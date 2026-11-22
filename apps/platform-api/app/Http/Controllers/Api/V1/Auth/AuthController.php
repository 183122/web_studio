<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Services\AuditLogger;
use App\Services\TenantProvisioner;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function register(Request $request, TenantProvisioner $provisioner): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:190', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
            'organizationName' => ['nullable', 'string', 'max:120'],
            'acceptTerms' => ['accepted'],
        ]);

        $user = DB::transaction(function () use ($data, $provisioner) {
            $user = User::create([
                'name' => $data['name'],
                'email' => mb_strtolower($data['email']),
                'password' => $data['password'],
            ]);
            $org = $provisioner->provision($user, $data['organizationName'] ?? null);
            $this->audit->record('auth.registered', $user, [], $org->id, null, $user->id);

            return $user;
        });

        event(new Registered($user));
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return (new UserResource($user))->response()->setStatusCode(201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        if (! Auth::guard('web')->attempt(['email' => mb_strtolower($data['email']), 'password' => $data['password']], (bool) ($data['remember'] ?? false))) {
            $this->audit->record('auth.login_failed', null, ['email_hash' => hash('sha256', mb_strtolower($data['email']))]);
            throw ValidationException::withMessages(['email' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง']);
        }
        $request->session()->regenerate();
        $user = $request->user();
        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->record('auth.login', $user, [], null, null, $user->id);

        return (new UserResource($user))->response();
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        if ($user) {
            $this->audit->record('auth.logout', $user, [], null, null, $user->id);
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    /** Current user with organizations, workspaces and roles. */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $workspaceRoles = WorkspaceMember::query()->where('user_id', $user->id)->pluck('role', 'workspace_id');
        $organizations = $user->organizations()->with(['plan', 'workspaces' => function ($q) use ($workspaceRoles) {
            $q->whereIn('id', $workspaceRoles->keys()->all())->orderBy('created_at');
        }])->get();

        return response()->json(['data' => [
            'user' => new UserResource($user),
            'organizations' => $organizations->map(fn ($org) => [
                'id' => $org->id,
                'name' => $org->name,
                'role' => $org->pivot->role,
                'plan' => $org->plan ? ['code' => $org->plan->code, 'name' => $org->plan->name] : null,
                'workspaces' => $org->workspaces->map(fn ($ws) => [
                    'id' => $ws->id,
                    'name' => $ws->name,
                    'slug' => $ws->slug,
                    'role' => $workspaceRoles[$ws->id] ?? null,
                ])->values(),
            ])->values(),
        ]]);
    }
}
