<?php

namespace Database\Seeders;

use App\Domain\Schema\DefinitionValidator;
use App\Domain\Schema\DocumentCodec;
use App\Domain\Schema\SchemaException;
use App\Models\Template;
use App\Models\TemplateVersion;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Imports curated templates from packages/templates/dist/*.json.
 * Each template is validated with the same pipeline as user documents; a new immutable
 * version is created only when the content hash changes.
 */
class TemplateSeeder extends Seeder
{
    public function run(): void
    {
        $dir = config('studio.packages_path').'/templates/dist';
        $files = glob($dir.'/*.json') ?: [];
        if ($files === []) {
            throw new RuntimeException("No templates found in {$dir}. Run: npm run build -w packages/templates");
        }
        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true, 128, JSON_THROW_ON_ERROR);
            $doc = $data['document'];
            $issues = DefinitionValidator::validate($doc);
            if ($issues !== []) {
                throw new SchemaException($issues, "Template {$data['slug']} is invalid");
            }
            $json = DocumentCodec::encode($doc);
            $hash = DocumentCodec::hash($json);

            $template = Template::query()->updateOrCreate(['slug' => $data['slug']], [
                'name' => $data['name'],
                'description' => $data['description'],
                'category' => $data['category'],
                'sort_order' => $data['sortOrder'],
                'is_published' => true,
            ]);
            $current = $template->current_version_id ? TemplateVersion::query()->find($template->current_version_id) : null;
            if ($current?->content_hash === $hash) {
                continue;
            }
            $version = TemplateVersion::create([
                'template_id' => $template->id,
                'version' => (int) TemplateVersion::query()->where('template_id', $template->id)->max('version') + 1,
                'document' => $json,
                'content_hash' => $hash,
            ]);
            $template->update(['current_version_id' => $version->id]);
        }
    }
}
