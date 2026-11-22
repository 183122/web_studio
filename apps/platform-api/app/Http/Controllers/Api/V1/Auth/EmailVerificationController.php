<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /** Signed link from the verification e-mail. Works without an active session. */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $frontend = config('studio.frontend_url');
        if (! $request->hasValidSignature(absolute: false)) {
            return redirect()->away($frontend.'/verify-email?status=invalid');
        }
        $user = User::query()->find($id);
        if ($user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return redirect()->away($frontend.'/verify-email?status=invalid');
        }
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
            app(AuditLogger::class)->record('auth.email_verified', $user, [], null, null, $user->id);
        }

        return redirect()->away($frontend.'/verify-email?status=verified');
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json(['data' => ['sent' => ! $user->hasVerifiedEmail()]]);
    }
}
