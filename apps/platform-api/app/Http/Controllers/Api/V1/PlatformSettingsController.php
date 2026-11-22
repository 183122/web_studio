<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Platform\GoogleOAuthSettings;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/** Platform admin settings (middleware: auth + EnsurePlatformAdmin). */
class PlatformSettingsController extends Controller
{
    public function __construct(private readonly GoogleOAuthSettings $google, private readonly AuditLogger $audit) {}

    public function showGoogle(): JsonResponse
    {
        return response()->json(['data' => $this->google->summary()]);
    }

    /** Changing a platform secret requires re-entering the admin's password. */
    private function confirmPassword(Request $request): void
    {
        $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check((string) $request->input('password'), $request->user()->password)) {
            throw ApiException::unprocessable('validation_failed', 'รหัสผ่านไม่ถูกต้อง', ['fields' => ['password' => ['รหัสผ่านไม่ถูกต้อง']]]);
        }
    }

    private function assertNotEnvironmentManaged(): void
    {
        if ($this->google->environmentManaged()) {
            throw ApiException::conflict('environment_managed', 'ค่านี้ถูกกำหนดผ่าน environment variables ของเซิร์ฟเวอร์ แก้ไขจากหน้านี้ไม่ได้');
        }
    }

    public function updateGoogle(Request $request): JsonResponse
    {
        $this->assertNotEnvironmentManaged();
        $hasStored = $this->google->credentials() !== null;
        $data = $request->validate([
            'clientId' => ['required', 'string', 'max:200', 'regex:'.GoogleOAuthSettings::CLIENT_ID_PATTERN],
            'clientSecret' => [$hasStored ? 'nullable' : 'required', 'string', 'regex:'.GoogleOAuthSettings::SECRET_PATTERN],
        ], [
            'clientId.regex' => 'Client ID ต้องอยู่ในรูปแบบ 1234567890-xxxx.apps.googleusercontent.com',
            'clientSecret.regex' => 'Client secret ไม่ถูกต้อง (คัดลอกจาก Google Cloud Console ทั้งค่า)',
            'clientSecret.required' => 'กรุณากรอก Client secret',
        ]);
        $this->confirmPassword($request);
        $this->google->save($data['clientId'], $data['clientSecret'] ?? null, $request->user());
        $this->audit->record('platform.google_oauth_updated', null, [
            'client_id' => $data['clientId'], 'secret_rotated' => ! empty($data['clientSecret']),
        ], actorId: $request->user()->id);

        return response()->json(['data' => $this->google->summary()]);
    }

    public function deleteGoogle(Request $request): JsonResponse
    {
        $this->assertNotEnvironmentManaged();
        $this->confirmPassword($request);
        $this->google->clear();
        $this->audit->record('platform.google_oauth_removed', null, [], actorId: $request->user()->id);

        return response()->json(['data' => $this->google->summary()]);
    }
}
