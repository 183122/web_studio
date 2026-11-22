<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Account\Totp;
use App\Domain\Account\TwoFactor;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** 2FA (TOTP) management and active sessions for the signed-in user. */
class SecurityController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    private function confirmPassword(Request $request): void
    {
        $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check((string) $request->input('password'), $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'รหัสผ่านไม่ถูกต้อง']);
        }
    }

    public function setupTwoFactor(Request $request): JsonResponse
    {
        $this->confirmPassword($request);
        $user = $request->user();
        if (TwoFactor::enabled($user)) {
            throw ApiException::conflict('two_factor_enabled', 'เปิดใช้การยืนยันสองขั้นตอนอยู่แล้ว');
        }
        $secret = Totp::newSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null])->save();

        return response()->json(['data' => ['secret' => $secret, 'otpauthUri' => Totp::uri($secret, $user->email, (string) config('app.name', 'AI Studio Cloud'))]]);
    }

    public function confirmTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();
        if (! $user->two_factor_secret || TwoFactor::enabled($user)) {
            throw ApiException::conflict('two_factor_not_pending', 'กรุณาเริ่มตั้งค่าใหม่');
        }
        if (! TwoFactor::check($user, $data['code'], null)) {
            throw ValidationException::withMessages(['code' => 'รหัสไม่ถูกต้อง ตรวจสอบเวลาในโทรศัพท์แล้วลองอีกครั้ง']);
        }
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $codes = TwoFactor::newRecoveryCodes($user);
        $this->audit->record('auth.two_factor_enabled', $user, [], null, null, $user->id);

        return response()->json(['data' => ['recoveryCodes' => $codes]]);
    }

    public function disableTwoFactor(Request $request): JsonResponse
    {
        $this->confirmPassword($request);
        $user = $request->user();
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $this->audit->record('auth.two_factor_disabled', $user, [], null, null, $user->id);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $this->confirmPassword($request);
        if (! TwoFactor::enabled($request->user())) {
            throw ApiException::conflict('two_factor_disabled', 'ยังไม่ได้เปิดใช้การยืนยันสองขั้นตอน');
        }

        return response()->json(['data' => ['recoveryCodes' => TwoFactor::newRecoveryCodes($request->user())]]);
    }

    public function sessions(Request $request): JsonResponse
    {
        $current = $request->session()->getId();
        $rows = config('session.driver') === 'database'
            ? DB::table(config('session.table', 'sessions'))->where('user_id', $request->user()->id)->orderByDesc('last_activity')->get()
            : collect();

        return response()->json(['data' => $rows->map(fn ($s) => [
            'id' => hash('sha256', $s->id), // never expose the raw session id
            'ip' => $s->ip_address, 'userAgent' => mb_substr((string) $s->user_agent, 0, 200),
            'lastActiveAt' => date(DATE_ATOM, (int) $s->last_activity), 'current' => hash_equals($s->id, (string) $current),
        ])->values()]);
    }

    public function revokeSession(Request $request, string $id): JsonResponse
    {
        $current = (string) $request->session()->getId();
        $table = config('session.table', 'sessions');
        $target = DB::table($table)->where('user_id', $request->user()->id)->get(['id'])->first(fn ($s) => hash_equals(hash('sha256', $s->id), $id));
        if ($target === null) {
            throw new ApiException(404, 'not_found', 'ไม่พบเซสชัน');
        }
        if (hash_equals($target->id, $current)) {
            throw ApiException::unprocessable('current_session', 'ใช้ "ออกจากระบบ" สำหรับเซสชันปัจจุบัน');
        }
        DB::table($table)->where('id', $target->id)->delete();
        $this->audit->record('auth.session_revoked', $request->user(), [], null, null, $request->user()->id);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function logoutOtherSessions(Request $request): JsonResponse
    {
        $this->confirmPassword($request);
        $n = DB::table(config('session.table', 'sessions'))->where('user_id', $request->user()->id)->where('id', '!=', (string) $request->session()->getId())->delete();
        $request->user()->forceFill(['remember_token' => null])->save(); // "remember me" cookies elsewhere stop working too
        $this->audit->record('auth.other_sessions_revoked', $request->user(), ['count' => $n], null, null, $request->user()->id);

        return response()->json(['data' => ['revoked' => $n]]);
    }
}
