<?php

namespace App\Domain\Publishing;

use App\Models\AppUser;
use App\Models\Project;
use App\Support\ApiException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * End-user accounts for published apps. Sessions are opaque random tokens in an HttpOnly,
 * host-only cookie (isolated per site); only SHA-256 hashes are stored.
 */
class AppUserAuth
{
    public const COOKIE = '__site_session';

    public const TTL_DAYS = 14;

    public function current(Request $request, Project $project): ?AppUser
    {
        $token = $request->cookie(self::COOKIE);
        if (! is_string($token) || strlen($token) !== 48) {
            return null;
        }
        $session = DB::table('app_user_sessions')->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first();
        if ($session === null) {
            return null;
        }
        $user = AppUser::query()->find($session->app_user_id);

        return $user && $user->project_id === $project->id && $user->environment === 'production' && $user->status === 'active' ? $user : null;
    }

    public function register(Project $project, string $name, string $email, string $password): AppUser
    {
        $email = mb_strtolower(trim($email));
        if (AppUser::query()->where('project_id', $project->id)->where('environment', 'production')->where('email', $email)->exists()) {
            throw ApiException::unprocessable('validation_failed', 'ข้อมูลไม่ถูกต้อง', ['fields' => ['email' => ['อีเมลนี้มีบัญชีอยู่แล้ว']]]);
        }

        return AppUser::create(['project_id' => $project->id, 'environment' => 'production', 'email' => $email, 'name' => $name, 'password' => $password, 'role' => 'member']);
    }

    public function attempt(Project $project, string $email, string $password): ?AppUser
    {
        $user = AppUser::query()->where('project_id', $project->id)->where('environment', 'production')->where('email', mb_strtolower(trim($email)))->first();
        if ($user === null || ! Hash::check($password, $user->password) || $user->status !== 'active') {
            static $dummy = null;
            $dummy ??= Hash::make(Str::random(20));
            Hash::check($password, $dummy); // similar timing for unknown accounts (no user enumeration)

            return null;
        }

        return $user;
    }

    public function startSession(Request $request, AppUser $user): Cookie
    {
        $token = Str::random(48);
        DB::table('app_user_sessions')->insert([
            'app_user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 250),
            'expires_at' => now()->addDays(self::TTL_DAYS),
            'created_at' => now(),
        ]);
        $user->forceFill(['last_login_at' => now()])->save();

        return new Cookie(self::COOKIE, $token, now()->addDays(self::TTL_DAYS), '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX);
    }

    public function endSession(Request $request): Cookie
    {
        $token = $request->cookie(self::COOKIE);
        if (is_string($token)) {
            DB::table('app_user_sessions')->where('token_hash', hash('sha256', $token))->delete();
        }

        return new Cookie(self::COOKIE, '', 1, '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX);
    }

    public function revokeAll(AppUser $user): void
    {
        DB::table('app_user_sessions')->where('app_user_id', $user->id)->delete();
    }
}
