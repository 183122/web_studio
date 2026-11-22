<?php

namespace Tests\Feature;

class AppApiTest extends DataTestCase
{
    /** Back to a browser-like studio request (no API key header). */
    private function studio(): static
    {
        return $this->flushHeaders()->withHeader('Origin', 'http://localhost:5173')->withHeader('Accept', 'application/json');
    }

    private function key(string $role, array $scopes): string
    {
        return $this->studio()->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/api-keys", ['name' => 'k', 'role' => $role, 'scopes' => $scopes])
            ->assertCreated()->json('data.token');
    }

    private function api(string $token, string $method, string $suffix = '', array $body = [])
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Origin' => ''])->json($method, "/api/v1/apps/{$this->project->id}/models/registrations/records{$suffix}", $body);
    }

    public function test_api_keys_with_scopes_roles_and_field_names(): void
    {
        $admin = $this->key('admin', ['records:read', 'records:write']);
        $this->assertMatchesRegularExpression('/^sk_[a-z0-9]{12}_[A-Za-z0-9]{40}$/', $admin);
        $this->studio()->actingAs($this->owner)->getJson("/api/v1/projects/{$this->project->id}/api-keys")->assertOk()->assertJsonMissingPath('data.0.token')->assertJsonMissingPath('data.0.key_hash');

        $created = $this->api($admin, 'POST', '', ['fields' => ['full_name' => 'API', 'email' => 'api@example.com', 'seats' => 2]])->assertCreated();
        $id = $created->json('data.id');
        $created->assertJsonPath('data.fields.email', 'api@example.com');
        $this->api($admin, 'POST', '', ['fields' => ['full_name' => 'X', 'email' => 'bad', 'password' => 'x']])
            ->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['password']]]]);
        $this->api($admin, 'POST', '', ['fields' => ['full_name' => 'X', 'email' => 'bad']])
            ->assertStatus(422)->assertJsonPath('error.details.fields.email', 'must be an e-mail address');
        $this->api($admin, 'GET')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.fields.full_name', 'API');
        $this->api($admin, 'PATCH', "/{$id}", ['fields' => ['status' => 'confirmed'], 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->api($admin, 'GET', "/{$id}")->assertOk()->assertJsonPath('data.fields.status', 'confirmed');

        // Scope and role enforcement
        $readOnly = $this->key('admin', ['records:read']);
        $this->api($readOnly, 'POST', '', ['fields' => ['full_name' => 'X', 'email' => 'x@example.com']])->assertStatus(403)->assertJsonPath('error.code', 'insufficient_scope');
        $public = $this->key('public', ['records:read', 'records:write']);
        $this->api($public, 'GET')->assertStatus(403)->assertJsonPath('error.code', 'app_permission_denied');
        $this->api($public, 'POST', '', ['fields' => ['full_name' => 'P', 'email' => 'p@example.com']])->assertCreated();
        $this->api($public, 'DELETE', "/{$id}")->assertStatus(403);
        $this->api($admin, 'DELETE', "/{$id}")->assertOk();
    }

    public function test_invalid_revoked_and_cross_project_keys(): void
    {
        $token = $this->key('admin', ['records:read']);
        $this->api('sk_bad', 'GET')->assertStatus(401);
        $this->api(substr($token, 0, -1).'X', 'GET')->assertStatus(401);

        [$other, $otherWs] = $this->tenant('Other');
        $otherProject = $this->project($other, $otherWs);
        $this->withHeaders(['Authorization' => "Bearer {$token}"])->getJson("/api/v1/apps/{$otherProject->id}/models/registrations/records")->assertNotFound();

        $keyId = $this->studio()->actingAs($this->owner)->getJson("/api/v1/projects/{$this->project->id}/api-keys")->json('data.0.id');
        $this->studio()->actingAs($this->owner)->deleteJson("/api/v1/projects/{$this->project->id}/api-keys/{$keyId}")->assertOk();
        $this->api($token, 'GET')->assertStatus(401);

        $viewer = $this->addMember($this->ws, 'viewer');
        $this->studio()->actingAs($viewer)->postJson("/api/v1/projects/{$this->project->id}/api-keys", ['name' => 'k', 'role' => 'admin', 'scopes' => ['records:read']])->assertStatus(403);
    }
}
