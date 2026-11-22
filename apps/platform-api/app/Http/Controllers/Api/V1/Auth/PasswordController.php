<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    /** Always answers the same way so that account existence is not disclosed. */
    public function forgot(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink(['email' => mb_strtolower($request->string('email'))]);

        return response()->json(['data' => ['message' => 'หากอีเมลนี้มีบัญชีอยู่ ระบบได้ส่งลิงก์ตั้งรหัสผ่านใหม่ให้แล้ว']]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
        ]);
        $status = Password::reset(
            ['email' => mb_strtolower($data['email']), 'password' => $data['password'], 'password_confirmation' => $request->input('password_confirmation'), 'token' => $data['token']],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                // Revoke every existing session for this account.
                DB::table('sessions')->where('user_id', $user->id)->delete();
                event(new PasswordReset($user));
                app(AuditLogger::class)->record('auth.password_reset', $user, [], null, null, $user->id);
            },
        );
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'ลิงก์ตั้งรหัสผ่านไม่ถูกต้องหรือหมดอายุ']);
        }

        return response()->json(['data' => ['message' => 'ตั้งรหัสผ่านใหม่เรียบร้อย กรุณาเข้าสู่ระบบ']]);
    }
}
