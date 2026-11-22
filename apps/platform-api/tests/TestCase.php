<?php

namespace Tests;

use App\Models\Plan;
use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProjectService;
use App\Services\TenantProvisioner;
use Database\Seeders\PlanSeeder;
use Database\Seeders\TemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected bool $seedTemplates = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        if ($this->seedTemplates) {
            $this->seed(TemplateSeeder::class);
        }
        // Behave like the SPA (stateful Sanctum requests).
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Accept', 'application/json');
    }

    /** @return array{0: User, 1: Workspace} */
    protected function tenant(string $name = 'Owner'): array
    {
        $user = User::factory()->create(['name' => $name]);
        $org = app(TenantProvisioner::class)->provision($user);
        $workspace = $org->workspaces()->firstOrFail();

        return [$user, $workspace];
    }

    /** Moves the organization of $this->ws (or the given workspace) to a plan. */
    protected function onPlan(string $code, ?Workspace $workspace = null): void
    {
        $workspace ??= $this->ws;
        $workspace->organization->forceFill(['plan_id' => Plan::query()->where('code', $code)->value('id')])->save();
    }

    protected function addMember(Workspace $workspace, string $role): User
    {
        $user = User::factory()->create();
        $workspace->organization->members()->attach($user->id, ['role' => 'member']);
        $workspace->members()->attach($user->id, ['role' => $role]);

        return $user;
    }

    protected function project(User $user, Workspace $workspace, string $name = 'เว็บไซต์ทดสอบ', ?string $template = null): Project
    {
        $tpl = $template ? Template::query()->where('slug', $template)->with('currentVersion')->firstOrFail() : null;

        return app(ProjectService::class)->create($workspace, $user, $name, '', $tpl);
    }
}
