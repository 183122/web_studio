<?php

namespace Tests\Feature;

use App\Domain\Platform\GoogleOAuthSettings;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformSettingsTest extends TestCase
{
    private const URL = '/api/v1/admin/settings/google-oauth';

    private const CLIENT_ID = '123456789012-abcdefghijklmnop0123456789.apps.googleusercontent.com';

    private const SECRET = 'dummy-test-secret_abcdefghijklmnopqrstuvwxyz12';

    private function admin(): User
    {
        [$user] = $this->tenant('Operator');
        $this->artisan('studio:platform-admin', ['email' => $user->email])->assertSuccessful();

        return $user->fresh();
    }

    public function test_only_platform_admins_can_reach_the_settings(): void
    {
        [$owner] = $this->tenant(); // an organisation owner is NOT a platform admin
        $this->actingAs($owner)->getJson(self::URL)->assertNotFound();
        $this->actingAs($owner)->putJson(self::URL, ['clientId' => self::CLIENT_ID, 'clientSecret' => self::SECRET, 'password' => 'Password12345'])->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson(self::URL)->assertUnauthorized();

        // Self-promotion through profile/registration payloads is impossible (not mass assignable).
        $this->postJson('/api/v1/auth/register', ['name' => 'x', 'email' => 'sneaky@example.com', 'password' => 'StrongPass123', 'password_confirmation' => 'StrongPass123', 'acceptTerms' => true, 'is_platform_admin' => true]);
        $this->assertFalse((bool) User::query()->where('email', 'sneaky@example.com')->value('is_platform_admin'));
    }

    public function test_admin_saves_client_secret_encrypted_and_never_returned(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->getJson('/api/v1/auth/me')->assertJsonPath('data.user.isPlatformAdmin', true);
        $this->actingAs($admin)->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.redirectUri', config('studio.frontend_url').'/api/v1/integrations/google/callback');

        $this->actingAs($admin)->putJson(self::URL, ['clientId' => self::CLIENT_ID, 'clientSecret' => self::SECRET, 'password' => 'wrong-password'])
            ->assertStatus(422)->assertJsonPath('error.details.fields.password.0', 'รหัสผ่านไม่ถูกต้อง');
        $this->actingAs($admin)->putJson(self::URL, ['clientId' => 'not-a-client-id', 'clientSecret' => self::SECRET, 'password' => 'Password12345'])->assertStatus(422);
        $this->actingAs($admin)->putJson(self::URL, ['clientId' => self::CLIENT_ID, 'password' => 'Password12345'])->assertStatus(422); // secret required first time

        $res = $this->actingAs($admin)->putJson(self::URL, ['clientId' => self::CLIENT_ID, 'clientSecret' => self::SECRET, 'password' => 'Password12345'])->assertOk()
            ->assertJsonPath('data.configured', true)->assertJsonPath('data.source', 'database')
            ->assertJsonPath('data.clientId', self::CLIENT_ID)->assertJsonPath('data.secretLast4', 'yz12')
            ->assertJsonPath('data.updatedBy', 'Operator');
        $this->assertStringNotContainsString(self::SECRET, $res->getContent());
        $this->assertStringNotContainsString(self::SECRET, $this->actingAs($admin)->getJson(self::URL)->getContent());

        // Encrypted at rest; readable only through the service.
        $raw = DB::table('platform_settings')->value('value');
        $this->assertStringNotContainsString(self::SECRET, $raw);
        $this->assertStringNotContainsString(self::CLIENT_ID, $raw);
        $this->assertSame(self::SECRET, app(GoogleOAuthSettings::class)->credentials()['clientSecret']);

        // Updating the client ID alone keeps the stored secret.
        $newId = '999999999999-zyxwvutsrqponmlk9876543210.apps.googleusercontent.com';
        $this->actingAs($admin)->putJson(self::URL, ['clientId' => $newId, 'password' => 'Password12345'])->assertOk();
        $this->assertSame(['clientId' => $newId, 'clientSecret' => self::SECRET, 'source' => 'database'], app(GoogleOAuthSettings::class)->credentials());

        $log = AuditLog::query()->where('action', 'platform.google_oauth_updated')->latest('id')->first();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertStringNotContainsString(self::SECRET, json_encode(AuditLog::query()->get()->toArray()));

        $this->actingAs($admin)->deleteJson(self::URL, ['password' => 'Password12345'])->assertOk()->assertJsonPath('data.configured', false);
        $this->assertNull(app(GoogleOAuthSettings::class)->credentials());
        $this->assertTrue(AuditLog::query()->where('action', 'platform.google_oauth_removed')->exists());
    }

    public function test_environment_variables_take_precedence_and_lock_the_form(): void
    {
        $admin = $this->admin();
        config(['services.google.client_id' => self::CLIENT_ID, 'services.google.client_secret' => self::SECRET]);
        $this->actingAs($admin)->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.source', 'environment')->assertJsonPath('data.environmentManaged', true)->assertJsonPath('data.secretLast4', 'yz12');
        $this->actingAs($admin)->putJson(self::URL, ['clientId' => self::CLIENT_ID, 'clientSecret' => self::SECRET, 'password' => 'Password12345'])
            ->assertStatus(409)->assertJsonPath('error.code', 'environment_managed');
    }

    public function test_admin_access_can_be_revoked(): void
    {
        $admin = $this->admin();
        $this->artisan('studio:platform-admin', ['email' => $admin->email, '--revoke' => true])->assertSuccessful();
        $this->actingAs($admin->fresh())->getJson(self::URL)->assertNotFound();
        $this->artisan('studio:platform-admin', ['email' => 'nobody@example.com'])->assertFailed();
    }
}
