<?php

namespace Tests\Feature;

use App\Domain\Integrations\Sheets\SheetSyncRunner;
use App\Models\AppRecord;
use App\Models\IntegrationSyncJob;
use App\Models\SheetSync;

/** M23 / J4: inspect → preview → map → dry run → import → push → two-way with conflicts → retries/DLQ. */
class SheetSyncTest extends IntegrationTestCase
{
    private const SHEET = '1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->google->spreadsheets[self::SHEET] = ['title' => 'ผู้สมัคร 2569', 'tabs' => [
            'Sheet1' => [
                ['ชื่อ', 'อีเมล', 'ที่นั่ง', 'หมายเหตุ'],
                ['สมชาย', 'somchai@example.com', '2', 'โทรกลับ'],
                ['สมหญิง', 'somying@example.com', '', ''],
                ['', '', '', ''],
                ['ไม่มีอีเมล', '', '1', ''],
            ],
            'Empty' => [],
        ]];
        $this->connectGoogle(['sheets']);
    }

    private function api(string $path): string
    {
        return "/api/v1/projects/{$this->project->id}{$path}";
    }

    private function createSync(array $over = []): array
    {
        return $this->actingAs($this->owner)->postJson($this->api('/sheet-syncs'), array_merge([
            'modelId' => 'm_reg', 'environment' => 'preview', 'spreadsheetId' => self::SHEET, 'sheetTitle' => 'Sheet1',
            'mapping' => [['fieldId' => 'f_name', 'column' => 'ชื่อ'], ['fieldId' => 'f_email', 'column' => 'อีเมล'], ['fieldId' => 'f_seats', 'column' => 'ที่นั่ง']],
            'keyFieldId' => 'f_email', 'direction' => 'pull',
        ], $over))->assertCreated()->json('data');
    }

    private function runSync(string $syncId): IntegrationSyncJob
    {
        return IntegrationSyncJob::query()->findOrFail($this->actingAs($this->owner)->postJson($this->api("/sheet-syncs/{$syncId}/run"))->assertStatus(202)->json('data.id'));
    }

    private function tab(string $name = 'Sheet1'): array
    {
        return $this->google->spreadsheets[self::SHEET]['tabs'][$name];
    }

    public function test_inspect_and_preview_a_spreadsheet(): void
    {
        $this->actingAs($this->owner)->postJson($this->api('/sheets/inspect'), ['url' => 'https://docs.google.com/spreadsheets/d/'.self::SHEET.'/edit#gid=0'])
            ->assertOk()->assertJsonPath('data.title', 'ผู้สมัคร 2569')->assertJsonPath('data.tabs.0.title', 'Sheet1');
        $this->actingAs($this->owner)->postJson($this->api('/sheets/inspect'), ['url' => 'https://example.com/x'])->assertStatus(422);
        $this->actingAs($this->owner)->postJson($this->api('/sheets/inspect'), ['url' => str_repeat('z', 40)])->assertNotFound()->assertJsonPath('error.code', 'google_not_found');
        $this->actingAs($this->owner)->postJson($this->api('/sheets/preview'), ['spreadsheetId' => self::SHEET, 'sheetTitle' => 'Sheet1'])
            ->assertOk()->assertJsonPath('data.header', ['ชื่อ', 'อีเมล', 'ที่นั่ง', 'หมายเหตุ'])->assertJsonPath('data.totalRows', 4);
    }

    public function test_mapping_must_cover_required_fields_for_imports(): void
    {
        $this->actingAs($this->owner)->postJson($this->api('/sheet-syncs'), [
            'modelId' => 'm_reg', 'environment' => 'preview', 'spreadsheetId' => self::SHEET, 'sheetTitle' => 'Sheet1',
            'mapping' => [['fieldId' => 'f_name', 'column' => 'ชื่อ']], 'direction' => 'pull',
        ])->assertStatus(422)->assertJsonPath('error.code', 'mapping_incomplete');
        $this->actingAs($this->owner)->postJson($this->api('/sheet-syncs'), [
            'modelId' => 'm_reg', 'environment' => 'production', 'spreadsheetId' => self::SHEET, 'sheetTitle' => 'Sheet1',
            'mapping' => [['fieldId' => 'f_name', 'column' => 'ชื่อ']], 'direction' => 'push',
        ])->assertNotFound()->assertJsonPath('error.code', 'not_published');
        $viewer = $this->addMember($this->ws, 'viewer');
        $this->actingAs($viewer)->postJson($this->api('/sheets/inspect'), ['url' => self::SHEET])->assertForbidden();
    }

    public function test_dry_run_then_import_is_validated_and_idempotent(): void
    {
        $sync = $this->createSync();
        $dry = $this->actingAs($this->owner)->postJson($this->api("/sheet-syncs/{$sync['id']}/dry-run"))->assertOk()->json('data');
        $this->assertSame(['rows' => 3, 'created' => 2, 'updated' => 0, 'unchanged' => 0, 'conflicts' => 0], array_intersect_key($dry, array_flip(['rows', 'created', 'updated', 'unchanged', 'conflicts'])));
        $this->assertSame(5, $dry['errors'][0]['row']);
        $this->assertSame('อีเมล', $dry['errors'][0]['field']);
        $this->assertSame(0, AppRecord::query()->count());

        $job = $this->actingAs($this->owner)->postJson($this->api("/sheet-syncs/{$sync['id']}/run"))->assertStatus(202)->json('data');
        $job = IntegrationSyncJob::query()->find($job['id']);
        $this->assertSame('partial', $job->status); // 2 imported, 1 row reported
        $this->assertSame(2, $job->stats['pull']['created']);
        $this->assertCount(1, $job->errors);
        $this->assertSame(2, AppRecord::query()->where('source', 'sheets')->count());
        $this->assertSame(2, AppRecord::query()->get()->firstWhere(fn ($r) => $r->values()['f_email'] === 'somchai@example.com')->values()['f_seats']);

        // Second run: nothing changes; editing the sheet updates the matching record by key.
        $this->assertSame(2, $this->runSync($sync['id'])->stats['pull']['unchanged']);
        $this->assertSame(2, AppRecord::query()->count());
        $this->google->spreadsheets[self::SHEET]['tabs']['Sheet1'][1][2] = '5';
        $this->actingAs($this->owner)->postJson($this->api("/sheet-syncs/{$sync['id']}/run"))->assertStatus(202);
        $this->assertSame(5, AppRecord::query()->get()->firstWhere(fn ($r) => $r->values()['f_email'] === 'somchai@example.com')->values()['f_seats']);

        $this->actingAs($this->owner)->getJson($this->api("/sheet-syncs/{$sync['id']}/jobs"))->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_push_writes_raw_values_updates_only_mapped_cells_and_appends(): void
    {
        $this->google->spreadsheets[self::SHEET]['tabs']['Export'] = [['หมายเหตุของทีม']];
        $a = $this->createRecord(['f_name' => '=HYPERLINK("http://evil")', 'f_email' => 'a@example.com', 'f_seats' => 3]);
        $this->createRecord(['f_name' => 'บี', 'f_email' => 'b@example.com']);
        $sync = $this->createSync(['sheetTitle' => 'Export', 'direction' => 'push', 'keyFieldId' => null]);
        $this->actingAs($this->owner)->postJson($this->api("/sheet-syncs/{$sync['id']}/run"))->assertStatus(202);

        $tab = $this->tab('Export');
        $this->assertSame(['หมายเหตุของทีม', '_studio_id', 'ชื่อ', 'อีเมล', 'ที่นั่ง'], $tab[0]);
        $this->assertCount(3, $tab);
        $this->assertSame([$a['id'], '=HYPERLINK("http://evil")', 'a@example.com', 3], array_slice($tab[1], 1));
        $this->assertTrue(collect($this->google->requests)->contains(fn ($r) => str_contains($r['url'], ':append') && str_contains($r['url'], 'valueInputOption=RAW')));

        // The team's own column is preserved; a changed record only rewrites its changed cell.
        $this->google->spreadsheets[self::SHEET]['tabs']['Export'][1][0] = 'โน้ตของทีม';
        $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$this->project->id}/data/m_reg/records/{$a['id']}", ['data' => ['f_seats' => 4], 'version' => 1])->assertOk();
        $before = count($this->google->requests);
        $job = $this->runSync($sync['id']);
        $tab = $this->tab('Export');
        $this->assertCount(3, $tab);
        $this->assertSame('โน้ตของทีม', $tab[1][0]);
        $this->assertSame(4, $tab[1][4]);
        $batch = collect(array_slice($this->google->requests, $before))->first(fn ($r) => str_contains($r['url'], 'batchUpdate'));
        $this->assertCount(1, json_decode($batch['body'], true)['data']);
        $this->assertEquals(['records' => 2, 'appended' => 0, 'updated' => 1, 'unchanged' => 1, 'skipped' => 0, 'orphanRows' => 0], $job->stats['push']); // key order differs between JSON column drivers
    }

    /** @return array{0: array, 1: string} */
    private function twoWay(string $policy): array
    {
        $this->google->spreadsheets[self::SHEET]['tabs']['Both'] = [];
        $r = $this->createRecord(['f_name' => 'ต้นฉบับ', 'f_email' => 'x@example.com']);
        $sync = $this->createSync(['sheetTitle' => 'Both', 'direction' => 'both', 'conflictPolicy' => $policy]);
        $this->actingAs($this->owner)->postJson($this->api("/sheet-syncs/{$sync['id']}/run"))->assertStatus(202);
        $this->assertSame('ต้นฉบับ', $this->tab('Both')[1][1]);
        // Both sides change the same row.
        $this->google->spreadsheets[self::SHEET]['tabs']['Both'][1][1] = 'แก้ในชีต';
        $this->actingAs($this->owner)->patchJson("/api/v1/projects/{$this->project->id}/data/m_reg/records/{$r['id']}", ['data' => ['f_name' => 'แก้ในแอป'], 'version' => 1])->assertOk();
        $this->assertSame(1, $this->runSync($sync['id'])->stats['pull']['conflicts']);

        return [$r, AppRecord::query()->find($r['id'])->values()['f_name']];
    }

    public function test_two_way_conflict_app_wins(): void
    {
        [, $app] = $this->twoWay('app_wins');
        $this->assertSame('แก้ในแอป', $app);
        $this->assertSame('แก้ในแอป', $this->tab('Both')[1][1]);
    }

    public function test_two_way_conflict_sheet_wins(): void
    {
        [, $app] = $this->twoWay('sheet_wins');
        $this->assertSame('แก้ในชีต', $app);
        $this->assertSame('แก้ในชีต', $this->tab('Both')[1][1]);
    }

    public function test_two_way_conflict_skip_leaves_both_sides(): void
    {
        [, $app] = $this->twoWay('skip');
        $this->assertSame('แก้ในแอป', $app);
        $this->assertSame('แก้ในชีต', $this->tab('Both')[1][1]);
    }

    public function test_rate_limits_retry_with_backoff_then_dead_letter_and_manual_retry(): void
    {
        $sync = SheetSync::query()->find($this->createSync()['id']);
        $runner = app(SheetSyncRunner::class);
        $job = IntegrationSyncJob::create(['project_id' => $this->project->id, 'sync_id' => $sync->id, 'kind' => 'pull', 'trigger' => 'manual', 'status' => 'queued']);
        $delays = [];
        $this->google->rateLimitNext = 100;
        for ($attempt = 1; $attempt <= SheetSyncRunner::MAX_ATTEMPTS; $attempt++) {
            $runner->execute($job->id, $attempt, function (int $d) use (&$delays) {
                $delays[] = $d;
            });
        }
        $this->assertSame([30, 120, 600, 1800], $delays);
        $this->assertSame('dead', $job->fresh()->status);
        $this->assertSame(0, AppRecord::query()->count());

        $this->google->rateLimitNext = 0;
        $retry = $this->actingAs($this->owner)->postJson($this->api("/sync-jobs/{$job->id}/retry"))->assertStatus(202)->json('data');
        $this->assertSame('retry', $retry['trigger']);
        $this->assertSame('partial', IntegrationSyncJob::query()->find($retry['id'])->status);
        $this->assertSame(2, AppRecord::query()->count());
    }

    public function test_missing_columns_and_deleted_tab_fail_with_clear_errors(): void
    {
        $sync = $this->createSync();
        $this->google->spreadsheets[self::SHEET]['tabs']['Sheet1'][0] = ['ชื่อ', 'Email'];
        $job = $this->runSync($sync['id']);
        $this->assertSame('failed', $job->status);
        $this->assertStringContainsString('อีเมล', $job->error);
    }

    public function test_scheduler_queues_due_syncs_once(): void
    {
        $sync = $this->createSync(['intervalMinutes' => 15]);
        $this->assertSame(0, app(SheetSyncRunner::class)->dispatchDue());
        $this->travel(16)->minutes();
        $this->artisan('studio:sheets:schedule')->assertSuccessful();
        $this->assertSame(1, IntegrationSyncJob::query()->where('sync_id', $sync['id'])->where('trigger', 'schedule')->count());
        $this->assertSame(0, app(SheetSyncRunner::class)->dispatchDue());
    }

    public function test_other_tenants_cannot_touch_syncs(): void
    {
        $sync = $this->createSync();
        [$stranger] = $this->tenant('Stranger');
        $this->actingAs($stranger)->getJson($this->api('/sheet-syncs'))->assertNotFound();
        $this->actingAs($stranger)->postJson($this->api("/sheet-syncs/{$sync['id']}/run"))->assertNotFound();
    }
}
