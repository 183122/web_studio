<?php

namespace Tests\Feature;

use App\Domain\Ai\AiProviderFactory;
use App\Domain\Ai\Providers\LocalRulesProvider;
use App\Domain\Schema\DefinitionValidator;
use App\Domain\Schema\DocumentCodec;
use App\Models\AiCredential;
use App\Models\AiRequest;
use App\Models\AppRevision;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAnthropicTransport;
use Tests\TestCase;

class AiPipelineTest extends TestCase
{
    private User $owner;

    private Workspace $ws;

    private Project $p;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->owner, $this->ws] = $this->tenant();
        $this->p = $this->project($this->owner, $this->ws, 'โปรเจกต์ AI');
        config(['studio.ai.anthropic_api_key' => null, 'studio.ai.max_retries' => 0]);
        $this->actingAs($this->owner);
    }

    private function url(string $suffix): string
    {
        return "/api/v1/projects/{$this->p->id}/ai/{$suffix}";
    }

    private function headDoc(): array
    {
        return $this->getJson("/api/v1/projects/{$this->p->id}/schema")->json('data');
    }

    private function useAnthropic(): FakeAnthropicTransport
    {
        config(['studio.ai.anthropic_api_key' => 'sk-ant-test-platform-key-000000']);
        $fake = new FakeAnthropicTransport;
        app(AiProviderFactory::class)->transporter = $fake;

        return $fake;
    }

    public function test_offline_plan_generate_apply_end_to_end(): void
    {
        $this->getJson($this->url('status'))->assertOk()->assertJsonPath('data.provider', 'local')->assertJsonPath('data.consentRequired', false);

        $req = $this->postJson($this->url('plan'), ['prompt' => 'อยากได้ระบบลงทะเบียนอบรมสำหรับโรงเรียน ชื่อ "อบรมครูยุคใหม่"'])->assertStatus(202);
        $id = $req->json('data.id');
        $plan = $this->getJson($this->url("requests/{$id}"))->assertOk()->assertJsonPath('data.status', 'succeeded')->json('data.result');
        $this->assertSame('อบรมครูยุคใหม่', $plan['siteName']);
        $this->assertSame('registrations', $plan['entities'][0]['name']);
        $this->assertNotEmpty($plan['missingRequirements']);
        $this->assertSame(1, $this->headDoc()['revision']); // planning never writes

        $proposal = $this->postJson($this->url("requests/{$id}/generate"))->assertCreated()->json('data');
        $this->assertSame('pending', $proposal['status']);
        $this->assertCount(2, $proposal['summary']['pagesAdded']);
        $this->assertSame('registrations', $proposal['summary']['modelsAdded'][0]['name']);
        $this->assertSame(['create'], $proposal['summary']['permissions'][0]['public']);
        $this->assertSame(1, $this->headDoc()['revision']); // nor does generating a proposal

        $this->postJson($this->url("proposals/{$proposal['id']}/apply"), ['baseRevision' => 1])->assertOk()->assertJsonPath('data.revision', 2);
        $doc = $this->headDoc()['document'];
        $this->assertSame([], DefinitionValidator::validate(DocumentCodec::decode(json_encode($doc))));
        $this->assertCount(3, $doc['pages']);
        $this->assertSame('อบรมครูยุคใหม่', $doc['name']);
        $types = array_count_values(array_column($doc['components'], 'type'));
        $this->assertSame(1, $types['Form']);
        $this->assertGreaterThanOrEqual(4, $types['FormField']);
        $this->assertSame(1, $types['DataTable']);
        $this->assertCount(1, $doc['workflows']);
        $this->assertSame('ai', AppRevision::query()->where('project_id', $this->p->id)->where('revision', 2)->value('source'));

        $this->postJson($this->url("proposals/{$proposal['id']}/apply"), ['baseRevision' => 2])->assertStatus(409)->assertJsonPath('error.code', 'ai_proposal_closed');

        // The generated app works: public form submission → record.
        $this->withHeader('X-Preview-Role', 'public')->postJson("/api/v1/projects/{$this->p->id}/preview-runtime/{$doc['dataModels'][0]['id']}/records", [
            'data' => [collect($doc['dataModels'][0]['fields'])->firstWhere('name', 'full_name')['id'] => 'ครูสมศรี', collect($doc['dataModels'][0]['fields'])->firstWhere('name', 'email')['id'] => 'somsri@example.com'],
            'meta' => ['hp' => '', 'elapsedMs' => 4000],
        ])->assertCreated();
    }

    public function test_all_offline_app_types_compile_to_valid_documents(): void
    {
        foreach (['ระบบจองคิวร้านตัดผม', 'ร้านขายเสื้อผ้าออนไลน์ มีหน้าสินค้า', 'เว็บไซต์บริษัทรับทำบัญชี'] as $prompt) {
            $project = $this->project($this->owner, $this->ws, mb_substr($prompt, 0, 20));
            $id = $this->postJson("/api/v1/projects/{$project->id}/ai/plan", ['prompt' => $prompt])->json('data.id');
            $proposal = $this->postJson("/api/v1/projects/{$project->id}/ai/requests/{$id}/generate")->assertCreated()->json('data.id');
            $this->postJson("/api/v1/projects/{$project->id}/ai/proposals/{$proposal}/apply", ['baseRevision' => 1])->assertOk();
            $project->forceDelete();
        }
    }

    public function test_stale_base_revision_and_rejection(): void
    {
        $id = $this->postJson($this->url('plan'), ['prompt' => 'เว็บไซต์ร้านกาแฟ'])->json('data.id');
        $proposal = $this->postJson($this->url("requests/{$id}/generate"))->json('data.id');
        $this->postJson("/api/v1/projects/{$this->p->id}/commands", ['baseRevision' => 1, 'commands' => [['type' => 'site.update', 'changes' => ['name' => 'แก้เอง']]]])->assertOk();
        $this->postJson($this->url("proposals/{$proposal}/apply"), ['baseRevision' => 1])->assertStatus(409)->assertJsonPath('error.code', 'revision_conflict');
        $this->postJson($this->url("proposals/{$proposal}/reject"))->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->postJson($this->url("proposals/{$proposal}/apply"), ['baseRevision' => 2])->assertStatus(409);
        $this->assertSame('แก้เอง', $this->headDoc()['document']['name']);
    }

    public function test_offline_patch_scoped_to_selection(): void
    {
        $doc = $this->headDoc()['document'];
        $root = $doc['pages'][0]['rootId'];
        $this->postJson("/api/v1/projects/{$this->p->id}/commands", ['baseRevision' => 1, 'commands' => [
            ['type' => 'component.insert', 'parentId' => $root, 'index' => 0, 'nodes' => [['id' => 'c_h', 'type' => 'Heading', 'props' => ['text' => 'เดิม', 'level' => 'h1'], 'styles' => new \stdClass, 'children' => []]]],
            ['type' => 'component.insert', 'parentId' => $root, 'index' => 1, 'nodes' => [['id' => 'c_t', 'type' => 'Text', 'props' => ['text' => 'อื่น'], 'styles' => new \stdClass, 'children' => []]]],
        ]])->assertOk();

        $id = $this->postJson($this->url('patch'), ['prompt' => 'เปลี่ยนข้อความเป็น "หัวข้อใหม่" และเปลี่ยนสีหลักเป็น #2563eb', 'componentId' => 'c_h', 'pageId' => $doc['pages'][0]['id']])->assertStatus(202)->json('data.id');
        $r = $this->getJson($this->url("requests/{$id}"))->assertJsonPath('data.status', 'succeeded')->json('data.result');
        $proposal = $this->getJson($this->url("proposals/{$r['proposalId']}"))->json('data');
        $ops = array_column($proposal['summary']['changes'], 'op');
        $this->assertEqualsCanonicalizing(['setText', 'setTheme'], $ops);
        $this->assertSame('เดิม', collect($proposal['summary']['changes'])->firstWhere('op', 'setText')['before']);
        $this->postJson($this->url("proposals/{$proposal['id']}/apply"), ['baseRevision' => 2])->assertOk();
        $doc = $this->headDoc()['document'];
        $this->assertSame('หัวข้อใหม่', $doc['components']['c_h']['props']['text']);
        $this->assertSame('อื่น', $doc['components']['c_t']['props']['text']);
        $this->assertSame('#2563eb', $doc['designTokens']['colors']['primary']);

        // Unknown instruction → actionable failure, nothing proposed.
        $id = $this->postJson($this->url('patch'), ['prompt' => 'ทำให้ดูดีขึ้น'])->json('data.id');
        $this->getJson($this->url("requests/{$id}"))->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error.code', 'ai_no_changes');
    }

    public function test_anthropic_provider_request_shape_usage_and_credits(): void
    {
        $fake = $this->useAnthropic();
        $this->getJson($this->url('status'))->assertJsonPath('data.provider', 'anthropic')->assertJsonPath('data.model', 'claude-opus-5-5')->assertJsonPath('data.consentRequired', true);
        $this->postJson($this->url('plan'), ['prompt' => 'ระบบรับสมัครสมาชิกชมรม'])->assertStatus(422)->assertJsonPath('error.code', 'ai_consent_required');

        $plan = (new LocalRulesProvider)->plan('ระบบลงทะเบียนอบรม');
        $plan['siteName'] = 'ชมรมถ่ายภาพ';
        $fake->message(json_encode($plan, JSON_UNESCAPED_UNICODE), 'end_turn', 3000, 2500);
        $id = $this->postJson($this->url('plan'), ['prompt' => 'ระบบรับสมัครสมาชิกชมรม ติดต่อ admin@club.example หรือ 081-234-5678', 'consent' => true])->assertStatus(202)->json('data.id');

        $r = AiRequest::query()->findOrFail($id);
        $this->assertSame('succeeded', $r->status);
        $this->assertSame('anthropic', $r->provider);
        $this->assertSame(3000, $r->input_tokens);
        $this->assertSame(62000, $r->cost_micro_usd); // 3000×$4 + 2500×$20 per MTok
        $this->assertSame(7, $r->credits_charged);   // ceil($0.062 / $0.01)
        $this->assertStringContainsString('[email]', $r->prompt_redacted);
        $this->assertStringContainsString('[phone]', $r->prompt_redacted);
        $this->assertSame('ชมรมถ่ายภาพ', $r->result['siteName']);

        $sent = $fake->requests[0];
        $this->assertStringEndsWith('/v1/messages', parse_url($sent['uri'], PHP_URL_PATH));
        $this->assertSame('claude-opus-5-5', $sent['body']['model']);
        $this->assertSame('json_schema', $sent['body']['output_config']['format']['type']);
        $this->assertSame('medium', $sent['body']['output_config']['effort']);
        $this->assertSame('default', $sent['body']['fallbacks']);
        $this->assertStringContainsString('server-side-fallback-2026-07-01', $sent['headers']['anthropic-beta'] ?? '');
        $this->assertStringContainsString('<app_description>', $sent['body']['messages'][0]['content']);
        $this->assertArrayNotHasKey('thinking', $sent['body']);
        $this->getJson($this->url('status'))->assertJsonPath('data.credits.used', 7);
    }

    public function test_provider_failures_never_corrupt_the_document(): void
    {
        $fake = $this->useAnthropic();
        $run = function () {
            $id = $this->postJson($this->url('plan'), ['prompt' => 'ระบบลงทะเบียนอบรม', 'consent' => true])->json('data.id');

            return AiRequest::query()->findOrFail($id);
        };

        // Outage → safe fallback to the offline planner.
        $fake->error(529, 'overloaded_error');
        $r = $run();
        $this->assertSame('succeeded', $r->status);
        $this->assertTrue($r->fallback_used);
        $this->assertSame('local', $r->provider);
        $this->assertSame(0, $r->credits_charged);

        // Invalid JSON → actionable error, tokens still metered.
        $fake->message('{"pages": [ oops');
        $r = $run();
        $this->assertSame(['failed', 'ai_invalid_output'], [$r->status, $r->error_code]);
        $this->assertGreaterThan(0, $r->credits_charged);

        // Refusal and auth failure → no fallback, clear error.
        $fake->message('{}', 'refusal');
        $this->assertSame('ai_refused', $run()->error_code);
        $fake->error(401, 'authentication_error');
        $this->assertSame('ai_auth_failed', $run()->error_code);

        // Truncated output
        $fake->message('{"summary":"x"', 'max_tokens');
        $this->assertSame('ai_truncated', $run()->error_code);

        $this->assertSame(1, $this->headDoc()['revision']);
        $this->assertSame(1, AppRevision::query()->where('project_id', $this->p->id)->count());
    }

    public function test_hostile_model_output_is_neutralised(): void
    {
        $fake = $this->useAnthropic();
        $evil = [
            'summary' => "<script>alert(1)</script>\u{0000}", 'confidence' => 7, 'missingRequirements' => [], 'assumptions' => [],
            'siteName' => str_repeat('ก', 500),
            'theme' => ['primary' => 'red;background:url(javascript:x)', 'secondary' => '#123456', 'accent' => 'x', 'headingFont' => 'Comic Sans', 'bodyFont' => 'Kanit'],
            'pages' => array_fill(0, 30, ['name' => '<img src=x onerror=alert(1)>', 'path' => '../../etc/passwd', 'title' => 't', 'description' => 'd', 'sections' => [
                ['kind' => 'hero', 'heading' => 'สวัสดี', 'text' => 'x', 'items' => [], 'buttonLabel' => 'คลิก', 'buttonHref' => 'javascript:alert(document.cookie)', 'entity' => '', 'fields' => []],
                ['kind' => 'iframe', 'heading' => 'x', 'text' => '', 'items' => [], 'buttonLabel' => '', 'buttonHref' => '', 'entity' => '', 'fields' => []],
                ['kind' => 'form', 'heading' => 'f', 'text' => '', 'items' => [], 'buttonLabel' => '', 'buttonHref' => '', 'entity' => 'ghost', 'fields' => []],
            ]]),
            'entities' => [['name' => 'DROP TABLE users;--', 'label' => 'x', 'fields' => [['name' => 'id', 'label' => 'id', 'type' => 'sql', 'required' => true, 'unique' => true, 'options' => []]]]],
            'permissions' => [['entity' => 'drop_table_users', 'public' => ['create', 'read', 'update', 'delete', 'admin'], 'member' => []]],
            'workflows' => [['name' => 'w', 'entity' => 'drop_table_users', 'event' => 'record.created', 'setFieldName' => '', 'setFieldValue' => '', 'emailTo' => 'evil@x', 'emailSubject' => 's', 'emailBody' => '{{password}}']],
        ];
        $fake->message(json_encode($evil, JSON_UNESCAPED_UNICODE));
        $id = $this->postJson($this->url('plan'), ['prompt' => 'แอป', 'consent' => true])->json('data.id');
        $plan = $this->getJson($this->url("requests/{$id}"))->assertJsonPath('data.status', 'succeeded')->json('data.result');
        $this->assertCount(8, $plan['pages']);
        $this->assertSame(1.0, (float) $plan['confidence']);
        $this->assertNull($plan['theme']['primary']);
        $this->assertNull($plan['theme']['headingFont']);
        $this->assertSame('', $plan['pages'][0]['sections'][0]['buttonHref']);
        $this->assertCount(2, $plan['pages'][0]['sections']); // unknown kind dropped
        $this->assertSame('drop_table_users', $plan['entities'][0]['name']);
        $this->assertSame('text', $plan['entities'][0]['fields'][0]['type']);

        $proposal = $this->postJson($this->url("requests/{$id}/generate"))->assertCreated()->json('data');
        $this->assertNotEmpty($proposal['summary']['warnings']);
        $this->postJson($this->url("proposals/{$proposal['id']}/apply"), ['baseRevision' => 1])->assertOk();
        $doc = $this->headDoc()['document'];
        $buttons = array_filter($doc['components'], fn ($c) => $c['type'] === 'Button');
        foreach ($buttons as $b) {
            $this->assertStringNotContainsString('javascript', $b['props']['href']);
        }
        $this->assertSame('id_2', $doc['dataModels'][0]['fields'][0]['name']); // reserved name avoided
        $this->assertSame(['create', 'read', 'update', 'delete'], $doc['permissions'][0]['rules']['public']);
        $this->assertSame([], $doc['workflows']); // invalid recipient and unknown placeholders → no workflow
    }

    public function test_credit_quota_byok_and_access_control(): void
    {
        $this->useAnthropic();
        $plan = $this->ws->organization->plan;
        $plan->update(['entitlements' => array_merge($plan->entitlements, ['ai_credits_monthly' => 5])]);
        $this->postJson($this->url('plan'), ['prompt' => 'แอป', 'consent' => true])->assertStatus(422)->assertJsonPath('error.code', 'ai_credits_exhausted');

        $this->putJson("/api/v1/workspaces/{$this->ws->id}/ai-credentials", ['provider' => 'anthropic', 'apiKey' => 'sk-ant-api03-BYOKkey1234567890abcdWXYZ'])
            ->assertOk()->assertJsonPath('data.last4', 'WXYZ')->assertJsonMissingPath('data.apiKey');
        $raw = DB::table('ai_credentials')->value('secret');
        $this->assertStringNotContainsString('BYOK', $raw);
        $this->assertSame('sk-ant-api03-BYOKkey1234567890abcdWXYZ', AiCredential::query()->first()->secret);
        $this->getJson($this->url('status'))->assertJsonPath('data.keySource', 'byok');
        $this->getJson("/api/v1/workspaces/{$this->ws->id}/ai-credentials")->assertJsonMissingPath('data.0.secret');

        $viewer = $this->addMember($this->ws, 'viewer');
        $this->actingAs($viewer)->postJson($this->url('plan'), ['prompt' => 'x'])->assertStatus(403);
        $this->actingAs($viewer)->putJson("/api/v1/workspaces/{$this->ws->id}/ai-credentials", ['provider' => 'anthropic', 'apiKey' => str_repeat('a', 30)])->assertStatus(403);
        [$intruder] = $this->tenant('Intruder');
        $this->actingAs($intruder)->getJson($this->url('status'))->assertNotFound();
    }
}
