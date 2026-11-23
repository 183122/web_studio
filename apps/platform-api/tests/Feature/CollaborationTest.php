<?php

namespace Tests\Feature;

/** Presence + head revision for live collaboration. */
class CollaborationTest extends DataTestCase
{
    public function test_presence_lists_active_collaborators_and_head_revision(): void
    {
        $editor = $this->addMember($this->ws, 'editor');
        $url = "/api/v1/projects/{$this->project->id}/presence";
        $this->actingAs($this->owner)->postJson($url, ['pageId' => 'pg_home', 'selectedId' => 'hero_1'])->assertOk()->assertJsonCount(1, 'data.participants');
        $res = $this->actingAs($editor)->postJson($url, ['pageId' => 'pg_home'])->assertOk()->json('data');
        $this->assertCount(2, $res['participants']);
        $owner = collect($res['participants'])->firstWhere('userId', $this->owner->id);
        $this->assertSame('hero_1', $owner['selectedId']);
        $this->assertFalse($owner['me']);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $owner['color']);

        $rev = $res['headRevision'];
        $this->applyCommands([['type' => 'site.update', 'changes' => ['seo' => ['siteName' => 'แก้โดยอีกคน']]]]);
        $this->actingAs($editor)->postJson($url, [])->assertJsonPath('data.headRevision', $rev + 1);

        // Stale participants drop out; leaving removes immediately; ids are validated.
        $this->travel(20)->seconds();
        $this->actingAs($editor)->postJson($url, [])->assertJsonCount(1, 'data.participants');
        $this->actingAs($editor)->deleteJson($url)->assertOk();
        $this->actingAs($this->owner)->postJson($url, [])->assertJsonCount(1, 'data.participants');
        $this->actingAs($this->owner)->postJson($url, ['selectedId' => '<script>'])->assertStatus(422);
        [$stranger] = $this->tenant('S');
        $this->actingAs($stranger)->postJson($url, [])->assertNotFound();
    }
}
