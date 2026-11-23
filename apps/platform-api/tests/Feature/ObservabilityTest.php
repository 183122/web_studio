<?php

namespace Tests\Feature;

use App\Http\Middleware\DirectNavigationIsFrontend;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ObservabilityTest extends TestCase
{
    public function test_request_id_is_generated_or_propagated_and_included_in_errors(): void
    {
        $res = $this->getJson('/api/health')->assertOk()->assertJsonPath('checks.database', 'ok');
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $res->headers->get('X-Request-Id'));
        $this->withHeader('X-Request-Id', 'edge-req-12345678')->getJson('/api/health')->assertHeader('X-Request-Id', 'edge-req-12345678');
        $this->withHeader('X-Request-Id', "bad\nvalue")->getJson('/api/health')->assertHeader('X-Request-Id');
        $err = $this->withHeader('X-Request-Id', 'trace-abcdefgh')->getJson('/api/v1/projects/01ARZ3NDEKTSV4RRFFQ69G5FAV');
        $err->assertStatus(401)->assertJsonPath('error.requestId', 'trace-abcdefgh');
    }

    public function test_health_reports_scheduler_and_storage(): void
    {
        Storage::fake('assets');
        $this->getJson('/api/health')->assertOk()->assertJsonPath('checks.storage', 'ok')->assertJsonPath('info.scheduler', 'unknown');
    }

    public function test_metrics_require_configured_token(): void
    {
        $this->get('/api/internal/metrics')->assertNotFound();
        config(['studio.metrics_token' => 'metrics-test-token-123']);
        $this->get('/api/internal/metrics', ['Authorization' => 'Bearer wrong'])->assertNotFound();
        $body = $this->get('/api/internal/metrics', ['Authorization' => 'Bearer metrics-test-token-123'])->assertOk()->getContent();
        $this->assertStringContainsString('studio_users_total ', $body);
        $this->assertStringContainsString('studio_audit_chain_intact 1', $body);
    }

    public function test_only_header_less_safe_api_navigations_are_treated_as_studio_requests(): void
    {
        $mw = new DirectNavigationIsFrontend;
        $run = function (string $method, array $headers, string $uri = '/api/v1/auth/me') use ($mw) {
            $cookies = isset($headers['Cookie']) ? [config('session.cookie') => 'abc'] : [];
            unset($headers['Cookie']);
            $req = Request::create($uri, $method, cookies: $cookies, server: array_combine(array_map(fn ($k) => 'HTTP_'.strtoupper($k), array_keys($headers)), array_values($headers)) ?: []);
            $mw->handle($req, fn () => new Response);

            return $req->headers->get('Referer');
        };
        $cookie = ['Cookie' => config('session.cookie').'=abc'];
        $this->assertSame(rtrim(config('studio.frontend_url'), '/').'/', $run('GET', $cookie));
        $this->assertNull($run('GET', [])); // no session cookie (API keys, health checks) → stays stateless
        $this->assertSame('https://evil.example/', $run('GET', ['Referer' => 'https://evil.example/'] + $cookie));
        $this->assertNull($run('GET', ['Origin' => 'https://evil.example']));
        $this->assertNull($run('POST', $cookie));
        $this->assertNull($run('GET', $cookie, '/api/runtime/v1/auth/me'));
    }

    public function test_health_reports_degraded_instead_of_crashing_when_the_cache_database_is_down(): void
    {
        // Shared hosting keeps cache in the database: when MySQL is down, every probe must fail softly.
        config([
            'database.connections.broken' => ['driver' => 'sqlite', 'database' => '/nonexistent/dir/db.sqlite', 'prefix' => ''],
            'cache.stores.database.connection' => 'broken',
            'cache.default' => 'database',
        ]);
        $this->getJson('/api/health')->assertStatus(503)->assertJsonPath('status', 'degraded')->assertJsonPath('checks.cache', 'error')
            ->assertJsonPath('info.scheduler', 'unknown');
    }
}
