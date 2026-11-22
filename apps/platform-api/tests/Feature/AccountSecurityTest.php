<?php

namespace Tests\Feature;

use App\Domain\Account\Totp;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** 2FA (TOTP + recovery codes), session management, invitations with seat limits. */
class AccountSecurityTest extends TestCase
{
    public function test_totp_matches_rfc6238_reference_vector(): void
    {
        // RFC 6238 Appendix B, SHA1 secret "12345678901234567890" (base32 GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ), T=59 → 94287082 (8 digits) → 287082.
        $this->assertSame('287082', Totp::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', intdiv(59, 30)));
        $this->assertSame('005924', Totp::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', intdiv(1234567890, 30)));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', Totp::newSecret());
    }

    private function enable2fa(User $user): array
    {
        $secret = $this->actingAs($user)->postJson('/api/v1/auth/two-factor', ['password' => 'Password12345'])->assertOk()->json('data.secret');
        $this->actingAs($user)->postJson('/api/v1/auth/two-factor/confirm', ['code' => '000000'])->assertStatus(422);
        $codes = $this->actingAs($user)->postJson('/api/v1/auth/two-factor/confirm', ['code' => Totp::code($secret, intdiv(time(), 30))])->assertOk()->json('data.recoveryCodes');
        $this->assertCount(10, $codes);

        return [$secret, $codes];
    }

    public function test_enable_2fa_then_login_requires_second_factor_with_replay_protection(): void
    {
        [$user] = $this->tenant();
        [$secret, $codes] = $this->enable2fa($user);
        $this->assertNotSame($secret, DB::table('users')->where('id', $user->id)->value('two_factor_secret')); // encrypted at rest
        $this->actingAs($user)->getJson('/api/v1/auth/me')->assertJsonPath('data.user.twoFactorEnabled', true);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/logout');

        $this->flushSession();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password12345'])->assertOk()->assertJsonPath('data.twoFactorRequired', true);
        $this->getJson('/api/v1/auth/me')->assertUnauthorized(); // not logged in yet
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => '123456'])->assertStatus(422);
        // Use the next time step: the step used to confirm enrolment can't be replayed.
        $code = Totp::code($secret, intdiv(time(), 30) + 1);
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => $code])->assertOk()->assertJsonPath('data.email', $user->email);
        $this->getJson('/api/v1/auth/me')->assertOk();

        // The same code cannot be replayed in another login.
        $this->postJson('/api/v1/auth/logout');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password12345'])->assertJsonPath('data.twoFactorRequired', true);
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => $code])->assertStatus(422);
        // A recovery code works once.
        $this->postJson('/api/v1/auth/two-factor-challenge', ['recoveryCode' => strtolower($codes[0])])->assertOk();
        $this->postJson('/api/v1/auth/logout');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password12345']);
        $this->postJson('/api/v1/auth/two-factor-challenge', ['recoveryCode' => $codes[0]])->assertStatus(422);
        $this->assertTrue(AuditLog::query()->where('action', 'auth.two_factor_failed')->exists());
    }

    public function test_challenge_expires_and_disable_requires_password(): void
    {
        [$user] = $this->tenant();
        [$secret] = $this->enable2fa($user);
        $this->actingAs($user)->postJson('/api/v1/auth/two-factor/disable', ['password' => 'wrong'])->assertStatus(422);
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password12345']);
        $this->travel(6)->minutes();
        $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => Totp::code($secret, intdiv(time(), 30))])->assertStatus(422)->assertJsonPath('error.code', 'two_factor_expired');
    }

    public function test_sessions_listing_and_revocation_are_scoped_to_the_user(): void
    {
        config(['session.driver' => 'database']);
        [$user] = $this->tenant();
        [$other] = $this->tenant('Other');
        foreach ([['s-mine-1', $user->id], ['s-mine-2', $user->id], ['s-other', $other->id]] as [$id, $uid]) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $uid, 'ip_address' => '203.0.113.9', 'user_agent' => 'Firefox', 'payload' => '', 'last_activity' => time()]);
        }
        $list = $this->actingAs($user)->getJson('/api/v1/auth/sessions')->assertOk()->json('data');
        $this->assertCount(2, $list);
        $this->assertNotContains('s-mine-1', array_column($list, 'id')); // raw ids never exposed
        $this->actingAs($user)->deleteJson('/api/v1/auth/sessions/'.hash('sha256', 's-other'))->assertNotFound();
        $this->actingAs($user)->deleteJson('/api/v1/auth/sessions/'.hash('sha256', 's-mine-1'))->assertOk();
        $this->actingAs($user)->postJson('/api/v1/auth/sessions/logout-others', ['password' => 'Password12345'])->assertOk();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count());
    }

    public function test_invitations_respect_seats_email_binding_and_expiry(): void
    {
        Mail::fake();
        [$owner, $ws] = $this->tenant();
        $invite = fn (string $email, string $role = 'editor') => $this->actingAs($owner)->postJson("/api/v1/workspaces/{$ws->id}/invitations", ['email' => $email, 'role' => $role]);
        $invite('friend@example.com')->assertCreated();
        // Free plan: 2 seats (owner + pending invite) → another invite is blocked.
        $invite('third@example.com')->assertStatus(422)->assertJsonPath('error.code', 'quota_exceeded');

        $token = null;
        $row = DB::table('invitations')->first();
        // Recreate with a known token (tokens are only stored hashed).
        $token = str_repeat('t', 48);
        DB::table('invitations')->where('id', $row->id)->update(['token_hash' => hash('sha256', $token)]);

        $friend = User::factory()->create(['email' => 'friend@example.com']);
        $stranger = User::factory()->create(['email' => 'stranger@example.com']);
        $this->actingAs($stranger)->getJson("/api/v1/invitations/{$token}")->assertOk()->assertJsonPath('data.emailMatches', false);
        $this->actingAs($stranger)->postJson("/api/v1/invitations/{$token}/accept")->assertStatus(422)->assertJsonPath('error.code', 'invitation_email_mismatch');
        $this->actingAs($friend)->postJson("/api/v1/invitations/{$token}/accept")->assertOk();
        $this->assertSame('editor', WorkspaceMember::query()->where('workspace_id', $ws->id)->where('user_id', $friend->id)->value('role'));
        $this->actingAs($friend)->postJson("/api/v1/invitations/{$token}/accept")->assertNotFound(); // single use

        // Editors cannot invite; roles can change; last admin protected.
        $this->actingAs($friend)->postJson("/api/v1/workspaces/{$ws->id}/invitations", ['email' => 'x@example.com', 'role' => 'viewer'])->assertForbidden();
        $this->actingAs($owner)->patchJson("/api/v1/workspaces/{$ws->id}/members/{$owner->id}", ['role' => 'viewer'])->assertStatus(422)->assertJsonPath('error.code', 'last_admin');
        $this->actingAs($owner)->deleteJson("/api/v1/workspaces/{$ws->id}/members/{$friend->id}")->assertOk();
        $this->actingAs($friend)->getJson("/api/v1/workspaces/{$ws->id}")->assertNotFound();

        // Upgrading adds seats.
        $ws->organization->forceFill(['plan_id' => Plan::query()->where('code', 'pro')->value('id')])->save();
        $invite('third@example.com')->assertCreated();
        $this->travel(8)->days();
        $t2 = str_repeat('u', 48);
        DB::table('invitations')->where('email', 'third@example.com')->update(['token_hash' => hash('sha256', $t2)]);
        $this->actingAs(User::factory()->create(['email' => 'third@example.com']))->getJson("/api/v1/invitations/{$t2}")->assertNotFound();
    }
}
