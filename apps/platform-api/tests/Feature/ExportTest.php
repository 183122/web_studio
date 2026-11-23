<?php

namespace Tests\Feature;

use App\Domain\Export\ProjectExporter;
use App\Models\AppApiKey;
use App\Services\AppDefinitionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/** M29: static site + data + schema + definition, labelled by capability, without secrets. */
class ExportTest extends DataTestCase
{
    private function download(string $query = ''): ZipArchive
    {
        $res = $this->actingAs($this->owner)->get("/api/v1/projects/{$this->project->id}/export{$query}")->assertOk();
        $this->assertSame('application/zip', $res->headers->get('Content-Type'));
        $tmp = tempnam(sys_get_temp_dir(), 'zt');
        file_put_contents($tmp, $res->streamedContent() ?: file_get_contents($res->baseResponse->getFile()->getPathname()));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp));

        return $zip;
    }

    private function names(ZipArchive $zip): array
    {
        return array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
    }

    public function test_full_export_contains_site_data_schema_and_no_secrets(): void
    {
        Storage::fake('assets');
        $assetId = $this->actingAs($this->owner)->post("/api/v1/workspaces/{$this->ws->id}/assets", ['file' => UploadedFile::fake()->image('logo.png', 20, 20)])->json('data.id');
        $root = app(AppDefinitionService::class)->head($this->project)['document']['pages'][0]['rootId'];
        $this->applyCommands([['type' => 'component.insert', 'parentId' => $root, 'index' => 0, 'nodes' => [['id' => 'img_1', 'type' => 'Image', 'props' => ['src' => "asset:{$assetId}", 'alt' => ''], 'styles' => (object) [], 'children' => []]]]]);
        $this->createRecord(['f_name' => "O'Brien \\ ทดสอบ", 'f_email' => 'quote@example.com', 'f_seats' => 3, 'f_status' => 'confirmed', 'f_vip' => true]);
        $this->createRecord(['f_name' => '=HYPERLINK("x")', 'f_email' => 'formula@example.com']);
        $this->actingAs($this->owner)->postJson("/api/v1/projects/{$this->project->id}/api-keys", ['name' => 'k', 'role' => 'admin', 'scopes' => ['records:read']]);

        $zip = $this->download('?data=preview');
        $names = $this->names($zip);
        $slug = $this->project->slug;
        foreach (['README.md', 'app.json', 'openapi.json', 'schema.sql', 'site/index.html', 'site/404.html', 'data/registrations.csv', 'data/registrations.json'] as $f) {
            $this->assertContains("{$slug}/{$f}", $names, $f);
        }
        $this->assertNotEmpty(array_filter($names, fn ($n) => str_starts_with($n, "{$slug}/site/_assets/{$assetId}.")));

        $html = $zip->getFromName("{$slug}/site/index.html");
        $this->assertStringContainsString('"static":true', $html);
        $this->assertStringContainsString('http-equiv="Content-Security-Policy"', $html);
        $this->assertStringContainsString("/_assets/{$assetId}.", $html);
        $this->assertStringNotContainsString($this->project->id, $html);

        $sql = $zip->getFromName("{$slug}/schema.sql");
        $this->assertStringContainsString('CREATE TABLE `registrations`', $sql);
        $this->assertStringContainsString('`email` VARCHAR(190) NOT NULL', $sql);
        $this->assertStringContainsString('UNIQUE KEY `uq_email`', $sql);
        $this->assertStringContainsString("CHECK (`status` IN ('pending', 'confirmed', 'cancelled'))", $sql);
        $this->assertStringContainsString("'O\\'Brien \\\\ ทดสอบ'", $sql); // escaped quote and backslash
        $this->assertStringContainsString(', 1, ', $sql); // boolean

        $csv = $zip->getFromName("{$slug}/data/registrations.csv");
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $readme = $zip->getFromName("{$slug}/README.md");
        $this->assertStringContainsString('Works without the platform?', $readme);
        $this->assertStringContainsString('registrations`, 2 rows', $readme);

        $all = implode("\n", array_map(fn ($n) => (string) $zip->getFromName($n), array_filter($names, fn ($n) => ! str_contains($n, '/runtime/') && ! str_contains($n, '_assets'))));
        $key = AppApiKey::query()->first();
        $this->assertStringNotContainsString((string) $key?->key_hash, $all);
        $this->assertDoesNotMatchRegularExpression('/sk_[A-Za-z0-9]{6,}_[A-Za-z0-9]{10,}/', $all); // real API keys
        $this->assertDoesNotMatchRegularExpression('/\$2y\$\d{2}\$/', $all); // bcrypt password hashes
        $this->assertStringNotContainsString('whsec_', $all);
    }

    public function test_export_without_data_and_access_control(): void
    {
        $this->createRecord(['f_name' => 'ลับ', 'f_email' => 'secret-person@example.com']);
        $zip = $this->download();
        $all = implode("\n", array_map(fn ($i) => (string) $zip->getFromIndex($i), range(0, $zip->numFiles - 1)));
        $this->assertStringNotContainsString('secret-person@example.com', $all);
        $this->assertStringContainsString('data: **not included**', $zip->getFromName("{$this->project->slug}/README.md"));

        $this->actingAs($this->owner)->get("/api/v1/projects/{$this->project->id}/export?source=live", ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('error.code', 'not_published');
        $viewer = $this->addMember($this->ws, 'viewer');
        $this->actingAs($viewer)->get("/api/v1/projects/{$this->project->id}/export", ['Accept' => 'application/json'])->assertForbidden();
        [$stranger] = $this->tenant('S');
        $this->actingAs($stranger)->get("/api/v1/projects/{$this->project->id}/export", ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_sql_string_escaping(): void
    {
        $this->assertSame("'a\\'b\\\\c\\nd'", ProjectExporter::sqlString("a'b\\c\nd"));
        $this->assertSame('NULL', ProjectExporter::sqlString(null));
    }
}
