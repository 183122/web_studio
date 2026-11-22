<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthTest extends TestCase
{
    private function registerPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'สมชาย ใจดี',
            'email' => 'Somchai@Example.com',
            'password' => 'StrongPass123',
            'password_confirmation' => 'StrongPass123',
            'organizationName' => 'โรงเรียนสมชาย',
            'acceptTerms' => true,
        ], $overrides);
    }

    public function test_registration_provisions_org_workspace_and_sends_verification(): void
    {
        Notification::fake();
        $res = $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertCreated();
        $res->assertJsonPath('data.email', 'somchai@example.com')->assertJsonPath('data.emailVerified', false);

        $user = User::query()->where('email', 'somchai@example.com')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmail::class);

        $me = $this->getJson('/api/v1/auth/me')->assertOk();
        $me->assertJsonPath('data.organizations.0.name', 'โรงเรียนสมชาย')
            ->assertJsonPath('data.organizations.0.role', 'owner')
            ->assertJsonPath('data.organizations.0.plan.code', 'free')
            ->assertJsonPath('data.organizations.0.workspaces.0.role', 'admin');
        $this->assertTrue(AuditLog::query()->where('action', 'auth.registered')->exists());
    }

    public function test_registration_validates_password_strength_terms_and_unique_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $this->postJson('/api/v1/auth/register', $this->registerPayload(['email' => 'taken@example.com', 'password' => 'short', 'password_confirmation' => 'short', 'acceptTerms' => false]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['email', 'password', 'acceptTerms']]]]);
    }

    public function test_login_logout_and_me(): void
    {
        $user = User::factory()->create(['email' => 'a@example.com', 'password' => 'Password12345']);
        $this->getJson('/api/v1/auth/me')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');

        $this->postJson('/api/v1/auth/login', ['email' => 'A@example.com', 'password' => 'wrong-password'])
            ->assertStatus(422);
        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'Password12345'])
            ->assertOk()->assertJsonPath('data.id', $user->id);
        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertGuest('web');
        $this->assertTrue(AuditLog::query()->where('action', 'auth.login_failed')->exists());
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'b@example.com']);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'b@example.com', 'password' => 'nope'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'b@example.com', 'password' => 'nope'])
            ->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    }

    public function test_email_verification_link(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/auth/register', $this->registerPayload())->assertCreated();
        $user = User::query()->where('email', 'somchai@example.com')->firstOrFail();

        $url = null;
        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $n) use ($user, &$url) {
            $url = $n->toMail($user)->actionUrl;

            return true;
        });
        $this->assertStringStartsWith('http://localhost:5173/api/v1/auth/email/verify/', $url);
        $path = substr($url, strlen('http://localhost:5173'));

        $this->get(str_replace('signature=', 'signature=x', $path))->assertRedirect('http://localhost:5173/verify-email?status=invalid');
        $this->assertNull($user->fresh()->email_verified_at);
        $this->get($path)->assertRedirect('http://localhost:5173/verify-email?status=verified');
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_password_reset_flow_revokes_sessions_and_does_not_enumerate(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'c@example.com']);
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk()->json('data.message');
        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'c@example.com'])->assertOk()->json('data.message');
        $this->assertSame($unknown, $known);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });
        $this->postJson('/api/v1/auth/reset-password', ['token' => 'bad', 'email' => 'c@example.com', 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123'])
            ->assertStatus(422);
        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => 'c@example.com', 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123'])
            ->assertOk();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->postJson('/api/v1/auth/login', ['email' => 'c@example.com', 'password' => 'NewPassword123'])->assertOk();
    }

    public function test_api_responses_carry_security_headers(): void
    {
        $this->getJson('/api/health')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertJsonPath('checks.database', 'ok');
    }
}
