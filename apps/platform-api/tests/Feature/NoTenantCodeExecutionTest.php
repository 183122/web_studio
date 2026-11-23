<?php

namespace Tests\Feature;

use App\Domain\Schema\CommandApplier;
use App\Domain\Schema\CommandException;
use App\Domain\Schema\Registry;
use App\Services\AppDefinitionService;

/**
 * Guard for docs/security/custom-code-sandbox-review.md: tenant code execution is not enabled.
 * Fails if a raw HTML/script component or a code-bearing command is introduced without a new review.
 */
class NoTenantCodeExecutionTest extends DataTestCase
{
    public function test_registry_has_no_code_capable_components_or_props(): void
    {
        $data = Registry::data();
        foreach ($data['components'] as $type => $def) {
            $this->assertDoesNotMatchRegularExpression('/html|script|code|embed|iframe|custom/i', $type, "component {$type} needs a security review");
            foreach ($def['props'] as $name => $prop) {
                $this->assertNotContains($prop['kind'], ['html', 'code', 'script', 'css', 'raw'], "{$type}.{$name}");
            }
        }
    }

    public function test_code_bearing_commands_are_rejected(): void
    {
        foreach (['code.upsert', 'script.add', 'function.deploy', 'html.insert'] as $type) {
            try {
                CommandApplier::apply(['components' => []], ['type' => $type]);
                $this->fail("{$type} accepted");
            } catch (CommandException $e) {
                $this->assertSame('unknown_command', $e->errorCode);
            }
        }
    }

    public function test_script_payloads_in_props_are_rejected_by_the_server(): void
    {
        $root = app(AppDefinitionService::class)->head($this->project)['document']['pages'][0]['rootId'];
        $res = $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/commands", ['baseRevision' => app(AppDefinitionService::class)->head($this->project)['revision'], 'commands' => [
            ['type' => 'component.insert', 'parentId' => $root, 'index' => 0, 'nodes' => [['id' => 'btn_x', 'type' => 'Button', 'props' => ['label' => 'x', 'href' => 'javascript:alert(1)'], 'styles' => (object) [], 'children' => []]]],
        ]]);
        $res->assertStatus(422);
    }
}
