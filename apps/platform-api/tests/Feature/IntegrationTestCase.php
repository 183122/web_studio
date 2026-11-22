<?php

namespace Tests\Feature;

use App\Models\GoogleConnection;
use Tests\Support\FakeGoogle;

abstract class IntegrationTestCase extends DataTestCase
{
    protected FakeGoogle $google;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => '123456789012-abcdefghijklmnop0123456789.apps.googleusercontent.com', 'services.google.client_secret' => 'test-client-secret-value-123']);
        $this->google = (new FakeGoogle)->install();
    }

    /** Runs the real connect → callback flow as the workspace owner. */
    protected function connectGoogle(array $features = ['sheets', 'drive']): GoogleConnection
    {
        $url = $this->actingAs($this->owner)->postJson("/api/v1/workspaces/{$this->ws->id}/integrations/google/connect", ['features' => $features])->assertOk()->json('data.url');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->actingAs($this->owner, 'web')->get('/api/v1/integrations/google/callback?'.http_build_query(['state' => $q['state'], 'code' => 'good-code']))
            ->assertRedirect(config('studio.frontend_url').'/app/integrations?google=connected');

        return GoogleConnection::query()->where('workspace_id', $this->ws->id)->sole();
    }
}
