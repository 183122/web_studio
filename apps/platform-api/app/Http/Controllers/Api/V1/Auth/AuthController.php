<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Account\TwoFactor;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Services\AuditLogger;
use App\Services\TenantProvisioner;
use App\Support\ApiException;
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
            // PDPA consent record: which versions of the terms/privacy notice were accepted, and when.
            $user->forceFill(['terms_version' => config('studio.legal.terms_version'), 'privacy_version' => config('studio.legal.privacy_version'), 'consented_at' => now()])->save();
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

        $guard = Auth::guard('web');
        if (! $guard->validate(['email' => mb_strtolower($data['email']), 'password' => $data['password']])) {
            $this->audit->record('auth.login_failed', null, ['email_hash' => hash('sha256', mb_strtolower($data['email']))]);
            throw ValidationException::withMessages(['email' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง']);
        }
        $user = User::query()->where('email', mb_strtolower($data['email']))->firstOrFail();
        if (TwoFactor::enabled($user)) {
            // Password OK but not logged in yet: a second factor is required within 5 minutes.
            $request->session()->regenerate();
            $request->session()->put('2fa.pending', ['user_id' => $user->id, 'remember' => (bool) ($data['remember'] ?? false), 'expires' => now()->addMinutes(5)->timestamp]);

            return response()->json(['data' => ['twoFactorRequired' => true]]);
        }
        $guard->login($user, (bool) ($data['remember'] ?? false));
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->record('auth.login', $user, [], null, null, $user->id);

        return (new UserResource($user))->response();
    }

    /** Second step of login for accounts with 2FA: TOTP code or a one-time recovery code. */
    public function twoFactorChallenge(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['nullable', 'string', 'max:10'], 'recoveryCode' => ['nullable', 'string', 'max:20']]);
        $pending = $request->session()->get('2fa.pending');
        if (! is_array($pending) || $pending['expires'] < now()->timestamp) {
            $request->session()->forget('2fa.pending');
            throw new ApiException(422, 'two_factor_expired', 'หมดเวลายืนยันตัวตน กรุณาเข้าสู่ระบบใหม่');
        }
        $user = User::query()->findOrFail($pending['user_id']);
        if (! TwoFactor::check($user, $data['code'] ?? null, $data['recoveryCode'] ?? null)) {
            $this->audit->record('auth.two_factor_failed', $user, [], null, null, $user->id);
            throw ValidationException::withMessages(['code' => 'รหัสยืนยันไม่ถูกต้อง']);
        }
        $request->session()->forget('2fa.pending');
        Auth::guard('web')->login($user, $pending['remember']);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        $this->audit->record('auth.login', $user, ['two_factor' => isset($data['recoveryCode']) ? 'recovery_code' : 'totp'], null, null, $user->id);

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
