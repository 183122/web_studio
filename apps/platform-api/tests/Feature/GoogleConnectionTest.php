<?php

namespace Tests\Feature;

use App\Domain\Integrations\Google\GoogleApiException;
use App\Domain\Integrations\Google\GoogleOAuth;
use App\Models\AuditLog;
use App\Models\GoogleConnection;
use App\Models\SheetSync;
use Illuminate\Support\Facades\DB;

/** M22 / J4 (connect + disconnect part): OAuth with PKCE, encrypted tokens, refresh, revoke. */
class GoogleConnectionTest extends IntegrationTestCase
{
    public function test_connect_uses_pkce_least_privilege_scopes_and_single_use_state(): void
    {
        $url = $this->actingAs($this->owner)->postJson("/api/v1/workspaces/{$this->ws->id}/integrations/google/connect", ['features' => ['sheets']])->assertOk()->json('data.url');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertSame('openid email https://www.googleapis.com/auth/spreadsheets', $q['scope']); // no Drive scope requested
        $this->assertSame('offline', $q['access_type']);
        $this->assertSame(config('studio.frontend_url').'/api/v1/integrations/google/callback', $q['redirect_uri']);

        // Another user cannot complete someone else's flow.
        [$intruder] = $this->tenant('Intruder');
        $this->actingAs($intruder, 'web')->get('/api/v1/integrations/google/callback?'.http_build_query(['state' => $q['state'], 'code' => 'good-code']))
            ->assertRedirect(config('studio.frontend_url').'/app/integrations?google=error&reason=state');
        $this->assertSame(0, GoogleConnection::query()->count());
    }

    public function test_full_connect_stores_encrypted_tokens_and_never_exposes_them(): void
    {
        $c = $this->connectGoogle();
        $this->assertSame('owner@gmail.example', $c->email);
        $this->assertSame('rt-secret-value', $c->refresh_token);
        $raw = DB::table('google_connections')->first();
        $this->assertStringNotContainsString('rt-secret-value', $raw->refresh_token);
        $this->assertStringNotContainsString('at-initial', $raw->access_token);
        $token = collect($this->google->requests)->firstWhere('url', 'https://oauth2.googleapis.com/token');
        $this->assertStringContainsString('code_verifier=', $token['body']);

        $res = $this->actingAs($this->owner)->getJson("/api/v1/workspaces/{$this->ws->id}/integrations/google")->assertOk()
            ->assertJsonPath('data.connection.email', 'owner@gmail.example')
            ->assertJsonPath('data.connection.features', ['sheets', 'drive']);
        $this->assertStringNotContainsString('rt-secret-value', $res->getContent());
        $this->assertStringNotContainsString('at-initial', $res->getContent());
        $this->assertTrue(AuditLog::query()->where('action', 'google.connected')->exists());
    }

    public function test_state_is_single_use_and_denied_consent_is_reported(): void
    {
        $url = $this->actingAs($this->owner)->postJson("/api/v1/workspaces/{$this->ws->id}/integrations/google/connect", ['features' => ['drive']])->json('data.url');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->actingAs($this->owner, 'web')->get('/api/v1/integrations/google/callback?'.http_build_query(['state' => $q['state'], 'error' => 'access_denied']))
            ->assertRedirect(config('studio.frontend_url').'/app/integrations?google=error&reason=denied');
        $this->actingAs($this->owner, 'web')->get('/api/v1/integrations/google/callback?'.http_build_query(['state' => $q['state'], 'code' => 'good-code']))
            ->assertRedirect(config('studio.frontend_url').'/app/integrations?google=error&reason=state');
    }

    public function test_only_workspace_admins_connect_and_platform_must_be_configured(): void
    {
        $editor = $this->addMember($this->ws, 'editor');
        $this->actingAs($editor)->postJson("/api/v1/workspaces/{$this->ws->id}/integrations/google/connect", ['features' => ['sheets']])->assertForbidden();
        $this->actingAs($editor)->getJson("/api/v1/workspaces/{$this->ws->id}/integrations/google")->assertOk()->assertJsonPath('data.canManage', false);
        [$stranger] = $this->tenant('Stranger');
        $this->actingAs($stranger)->getJson("/api/v1/workspaces/{$this->ws->id}/integrations/google")->assertNotFound();

        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);
        $this->actingAs($this->owner)->getJson("/api/v1/workspaces/{$this->ws->id}/integrations/google")->assertJsonPath('data.platformConfigured', false);
        $this->actingAs($this->owner)->postJson("/api/v1/workspaces/{$this->ws->id}/integrations/google/connect", ['features' => ['sheets']])
            ->assertStatus(422)->assertJsonPath('error.code', 'google_not_configured');
    }

    public function test_access_token_refresh_and_revoked_refresh_token(): void
    {
        $c = $this->connectGoogle();
        $c->forceFill(['expires_at' => now()->subMinute()])->save();
        $oauth = app(GoogleOAuth::class);
        $this->assertStringStartsWith('at-', $oauth->accessToken($c->fresh()));
        $this->assertTrue($c->fresh()->expires_at->isFuture());

        $this->google->refreshRevoked = true;
        $c->fresh()->forceFill(['expires_at' => now()->subMinutes(5)])->save();
        try {
            $oauth->accessToken($c->fresh());
            $this->fail('expected auth failure');
        } catch (GoogleApiException $e) {
            $this->assertSame('auth', $e->kind);
        }
        $this->assertSame('revoked', $c->fresh()->status);
    }

    public function test_j4_disconnect_revokes_at_google_deletes_tokens_and_pauses_syncs(): void
    {
        $this->connectGoogle();
        $sync = SheetSync::create(['project_id' => $this->project->id, 'environment' => 'preview', 'model_id' => 'm_reg', 'spreadsheet_id' => str_repeat('a', 30), 'sheet_title' => 'Sheet1', 'mapping' => [['fieldId' => 'f_name', 'column' => 'ชื่อ']], 'direction' => 'push']);
        $this->actingAs($this->owner)->deleteJson("/api/v1/workspaces/{$this->ws->id}/integrations/google")->assertOk()->assertJsonPath('data.revokedAtGoogle', true);
        $this->assertTrue($this->google->revoked);
        $this->assertSame(0, GoogleConnection::query()->count());
        $this->assertSame('disconnected', $sync->fresh()->status);
        $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/sheet-syncs/{$sync->id}/run")->assertStatus(409);
        $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/sheets/inspect", ['url' => str_repeat('a', 30)])
            ->assertStatus(422)->assertJsonPath('error.code', 'google_not_connected');
    }
}
