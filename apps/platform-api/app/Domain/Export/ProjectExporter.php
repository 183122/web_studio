<?php

namespace App\Domain\Export;

use App\Domain\Data\CsvService;
use App\Domain\Data\DataContext;
use App\Domain\Data\RecordService;
use App\Domain\Schema\DocumentCodec;
use App\Domain\Schema\Upcaster;
use App\Http\Controllers\RuntimeController;
use App\Models\AppDefinition;
use App\Models\Asset;
use App\Models\Project;
use App\Models\Release;
use App\Support\ApiException;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * M29 code export. One ZIP, labelled by capability:
 *  - site/      static website rendered by the same runtime bundle (works on any static host at the domain root)
 *  - data/      records per table as CSV (formula-safe) and JSON
 *  - schema.sql MySQL tables (typed columns, NOT NULL, UNIQUE, CHECK for options) + INSERTs
 *  - app.json   the canonical AppDefinition (re-importable)
 *  - openapi.json  the app's REST data API (served by the platform)
 *  - README.md  what works without the platform and how to deploy
 * Never included: API keys, webhook secrets, OAuth tokens, end-user password hashes.
 */
class ProjectExporter
{
    public const MAX_ROWS_PER_MODEL = 50000;

    public function __construct(private readonly RecordService $records, private readonly CsvService $csv) {}

    /** @return string path of the temporary ZIP (caller deletes it) */
    public function export(Project $project, string $source, ?string $dataEnv): string
    {
        $doc = $this->document($project, $source);
        $path = tempnam(sys_get_temp_dir(), 'export_').'.zip';
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new ApiException(500, 'export_failed', 'สร้างไฟล์ส่งออกไม่สำเร็จ');
        }
        $root = preg_replace('/[^a-z0-9-]+/', '-', strtolower($project->slug)) ?: 'project';

        $assetFiles = $this->addAssets($zip, $project, $doc['assets'], "{$root}/site/_assets");
        $this->addSite($zip, $doc, "{$root}/site", $assetFiles);
        $zip->addFromString("{$root}/app.json", json_encode(DocumentCodec::restoreObjects($doc), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->addFromString("{$root}/openapi.json", json_encode($this->openApi($project, $doc), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $counts = $dataEnv ? $this->addData($zip, $project, $doc, $dataEnv, $root) : [];
        $zip->addFromString("{$root}/schema.sql", $this->schemaSql($doc, $dataEnv ? $this->rowsForSql($project, $doc, $dataEnv) : []));
        $zip->addFromString("{$root}/README.md", $this->readme($project, $doc, $source, $dataEnv, $counts));
        $zip->close();

        return $path;
    }

    private function document(Project $project, string $source): array
    {
        if ($source === 'live') {
            $release = $project->live_release_id ? Release::query()->find($project->live_release_id) : null;
            if ($release === null) {
                throw ApiException::unprocessable('not_published', 'เว็บไซต์ยังไม่ได้เผยแพร่ เลือกส่งออกจากฉบับร่างแทน');
            }

            return Upcaster::upcast(DocumentCodec::decode($release->document));
        }

        return Upcaster::upcast(DocumentCodec::decode(AppDefinition::query()->where('project_id', $project->id)->firstOrFail()->document));
    }

    /** @return array<string, string> asset id → site-relative URL */
    private function addAssets(ZipArchive $zip, Project $project, array $ids, string $dir): array
    {
        $map = [];
        foreach (Asset::query()->where('workspace_id', $project->workspace_id)->whereIn('id', $ids)->get() as $a) {
            if (! $a->isServable() || ! Storage::disk($a->disk)->exists($a->path)) {
                continue;
            }
            $file = $a->id.'.'.(pathinfo($a->path, PATHINFO_EXTENSION) ?: 'bin');
            $zip->addFromString("{$dir}/{$file}", Storage::disk($a->disk)->get($a->path));
            $map[$a->id] = "/_assets/{$file}";
        }

        return $map;
    }

    private function addSite(ZipArchive $zip, array $doc, string $dir, array $assetFiles): void
    {
        $public = DocumentCodec::restoreObjects(RuntimeController::publicDocument($doc));
        $pageFile = fn (string $path) => $path === '/' ? 'index.html' : ltrim($path, '/').'/index.html';
        $site = $doc['seo']['siteName'] ?? '';
        foreach ($doc['pages'] as $page) {
            $title = ($page['seo']['title'] ?: $page['name']).($site ? ' | '.$site : '');
            $bootstrap = ['release' => ['version' => 'export', 'hash' => hash('sha256', json_encode($public))], 'document' => $public, 'path' => $page['path'], 'static' => true, 'assetFiles' => (object) $assetFiles];
            $zip->addFromString("{$dir}/".$pageFile($page['path']), RuntimeController::shell($title, '', $bootstrap, (bool) $page['seo']['noindex'], $page['seo']['description'] ?: $doc['seo']['description'], $doc['seo']['lang'], true));
        }
        $nf = ['release' => ['version' => 'export', 'hash' => ''], 'document' => $public, 'path' => '/404', 'static' => true, 'assetFiles' => (object) $assetFiles];
        $zip->addFromString("{$dir}/404.html", RuntimeController::shell('ไม่พบหน้า', '', $nf, true, '', $doc['seo']['lang'], true));
        // Runtime bundle (same build the platform serves).
        $runtime = public_path('runtime');
        if (is_dir($runtime)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($runtime, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $rel = substr($file->getPathname(), strlen($runtime) + 1);
                if (! str_starts_with($rel, '.vite')) {
                    $zip->addFile($file->getPathname(), "{$dir}/runtime/{$rel}");
                }
            }
        }
    }

    private function addData(ZipArchive $zip, Project $project, array $doc, string $env, string $root): array
    {
        $ctx = DataContext::forEnvironment($project, $env);
        $counts = [];
        foreach ($doc['dataModels'] as $model) {
            $stream = $this->csv->export($ctx, $model, $env);
            $zip->addFromString("{$root}/data/{$model['name']}.csv", stream_get_contents($stream));
            fclose($stream);
            $rows = [];
            $this->records->scope($ctx, $model, $env)->orderBy('created_at')->limit(self::MAX_ROWS_PER_MODEL)->get()
                ->each(function ($r) use (&$rows, $model) {
                    $v = $r->values();
                    $fields = [];
                    foreach ($model['fields'] as $f) {
                        $fields[$f['name']] = $v[$f['id']] ?? null;
                    }
                    $rows[] = ['id' => $r->id, 'fields' => $fields, 'createdAt' => $r->created_at?->toIso8601String(), 'updatedAt' => $r->updated_at?->toIso8601String()];
                });
            $zip->addFromString("{$root}/data/{$model['name']}.json", json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $counts[$model['name']] = count($rows);
        }

        return $counts;
    }

    private function rowsForSql(Project $project, array $doc, string $env): array
    {
        $ctx = DataContext::forEnvironment($project, $env);
        $out = [];
        foreach ($doc['dataModels'] as $model) {
            $out[$model['id']] = $this->records->scope($ctx, $model, $env)->orderBy('created_at')->limit(self::MAX_ROWS_PER_MODEL)->get();
        }

        return $out;
    }

    public static function sqlString(?string $s): string
    {
        if ($s === null) {
            return 'NULL';
        }

        return "'".strtr($s, ['\\' => '\\\\', "'" => "\\'", "\0" => '\\0', "\n" => '\\n', "\r" => '\\r', "\x1a" => '\\Z'])."'";
    }

    private static function ident(string $name): string
    {
        return '`'.preg_replace('/[^a-z0-9_]/', '', strtolower($name)).'`';
    }

    public function schemaSql(array $doc, array $rowsByModel): string
    {
        $sql = "-- Generated by AI Studio Cloud for MySQL 8.0+\n-- Tables mirror the app's data models; ids are the platform's record ids (ULID).\nSET NAMES utf8mb4;\n\n";
        foreach ($doc['dataModels'] as $m) {
            $cols = ['  `id` CHAR(26) NOT NULL'];
            $extra = [];
            foreach ($m['fields'] as $f) {
                $type = match ($f['type']) {
                    'text' => 'VARCHAR('.min(max((int) ($f['maxLength'] ?? 255), 1), 2000).')',
                    'longtext' => 'TEXT',
                    'email' => 'VARCHAR(190)',
                    'phone' => 'VARCHAR(40)',
                    'url' => 'VARCHAR(2048)',
                    'number' => 'DECIMAL(30,10)',
                    'integer' => 'BIGINT',
                    'boolean' => 'TINYINT(1)',
                    'date' => 'DATE',
                    'datetime' => 'DATETIME',
                    'select' => 'VARCHAR(200)',
                    default => 'TEXT',
                };
                $cols[] = '  '.self::ident($f['name']).' '.$type.($f['required'] ? ' NOT NULL' : ' NULL').' COMMENT '.self::sqlString($f['label']);
                if ($f['unique']) {
                    $extra[] = '  UNIQUE KEY '.self::ident('uq_'.$f['name']).' ('.self::ident($f['name']).($type === 'TEXT' ? '(191)' : '').')';
                }
                if ($f['type'] === 'select' && ! empty($f['options'])) {
                    $extra[] = '  CONSTRAINT '.self::ident('chk_'.$m['name'].'_'.$f['name']).' CHECK ('.self::ident($f['name']).' IN ('.implode(', ', array_map(fn ($o) => self::sqlString((string) $o), $f['options'])).'))';
                }
            }
            $cols[] = '  `created_at` DATETIME NULL';
            $cols[] = '  `updated_at` DATETIME NULL';
            $sql .= 'CREATE TABLE '.self::ident($m['name'])." (\n".implode(",\n", array_merge($cols, ['  PRIMARY KEY (`id`)'], $extra))."\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT=".self::sqlString($m['label']).";\n\n";
            foreach ($rowsByModel[$m['id']] ?? [] as $r) {
                $v = $r->values();
                $vals = [self::sqlString($r->id)];
                foreach ($m['fields'] as $f) {
                    $x = $v[$f['id']] ?? null;
                    $vals[] = match (true) {
                        $x === null => 'NULL',
                        is_bool($x) => $x ? '1' : '0',
                        is_int($x), is_float($x) => (string) $x,
                        $f['type'] === 'datetime' => self::sqlString(date('Y-m-d H:i:s', strtotime((string) $x))),
                        default => self::sqlString(is_scalar($x) ? (string) $x : json_encode($x, JSON_UNESCAPED_UNICODE)),
                    };
                }
                $vals[] = self::sqlString($r->created_at?->format('Y-m-d H:i:s'));
                $vals[] = self::sqlString($r->updated_at?->format('Y-m-d H:i:s'));
                $sql .= 'INSERT INTO '.self::ident($m['name']).' VALUES ('.implode(', ', $vals).");\n";
            }
            $sql .= "\n";
        }

        return $sql;
    }

    private function openApi(Project $project, array $doc): array
    {
        $paths = [];
        $schemas = [];
        foreach ($doc['dataModels'] as $m) {
            $props = [];
            foreach ($m['fields'] as $f) {
                $props[$f['name']] = match ($f['type']) {
                    'number' => ['type' => 'number'], 'integer' => ['type' => 'integer'], 'boolean' => ['type' => 'boolean'],
                    'date' => ['type' => 'string', 'format' => 'date'], 'datetime' => ['type' => 'string', 'format' => 'date-time'],
                    'email' => ['type' => 'string', 'format' => 'email'], 'url' => ['type' => 'string', 'format' => 'uri'],
                    'select' => ['type' => 'string', 'enum' => $f['options'] ?? []],
                    default => ['type' => 'string'] + (isset($f['maxLength']) ? ['maxLength' => $f['maxLength']] : []),
                } + ['description' => $f['label']];
            }
            $schemas[$m['name']] = ['type' => 'object', 'properties' => $props, 'required' => array_values(array_map(fn ($f) => $f['name'], array_filter($m['fields'], fn ($f) => $f['required'])))];
            $ref = ['$ref' => "#/components/schemas/{$m['name']}"];
            $paths["/models/{$m['name']}/records"] = [
                'get' => ['summary' => "List {$m['label']}", 'parameters' => [['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']], ['name' => 'search', 'in' => 'query', 'schema' => ['type' => 'string']]], 'responses' => ['200' => ['description' => 'OK']]],
                'post' => ['summary' => "Create {$m['label']}", 'requestBody' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['fields' => $ref]]]]], 'responses' => ['201' => ['description' => 'Created']]],
            ];
            $paths["/models/{$m['name']}/records/{id}"] = [
                'get' => ['summary' => 'Get one', 'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], 'responses' => ['200' => ['description' => 'OK']]],
                'patch' => ['summary' => 'Update', 'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], 'responses' => ['200' => ['description' => 'OK']]],
                'delete' => ['summary' => 'Delete', 'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]], 'responses' => ['200' => ['description' => 'OK']]],
            ];
        }

        return [
            'openapi' => '3.1.0',
            'info' => ['title' => ($doc['seo']['siteName'] ?: $project->name).' — Data API', 'version' => '1', 'description' => 'Hosted by AI Studio Cloud. Authenticate with an API key created in the project data admin (Bearer sk_…). Permissions follow the app roles.'],
            'servers' => [['url' => rtrim((string) config('app.url'), '/')."/api/v1/apps/{$project->id}"]],
            'components' => ['securitySchemes' => ['apiKey' => ['type' => 'http', 'scheme' => 'bearer']], 'schemas' => (object) $schemas],
            'security' => [['apiKey' => []]],
            'paths' => (object) $paths,
        ];
    }

    private function readme(Project $project, array $doc, string $source, ?string $env, array $counts): string
    {
        $models = $doc['dataModels'] === [] ? '—' : implode(', ', array_map(fn ($m) => $m['label'].' (`'.$m['name'].'`'.(isset($counts[$m['name']]) ? ", {$counts[$m['name']]} rows" : '').')', $doc['dataModels']));
        $hasForms = (bool) array_filter($doc['components'], fn ($n) => in_array($n['type'], ['Form', 'DataTable'], true));
        $date = now()->toIso8601String();
        $envLabel = $env === null ? 'not included' : ($env === 'production' ? 'live site data' : 'preview (test) data');
        $formsNote = $hasForms ? "\n> This project has forms or data tables. In the static export they show a notice instead of sending/reading data.\n" : '';

        return <<<MD
# {$project->name} — export

Exported {$date} from AI Studio Cloud · source: **{$source}** · data: **{$envLabel}**

## What you get (capabilities)

| Part | Folder | Works without the platform? |
|---|---|---|
| Website pages, layout, theme, images, links, SEO | `site/` | ✅ Yes — upload to any static host |
| Forms, data tables, sign-in for end users | `site/` | ❌ No — needs a server; they show a notice in the static export |
| Workflows (status updates, e-mails) | `app.json` | ❌ No — definitions only, no server code is generated |
| Data | `data/*.csv`, `data/*.json`, `schema.sql` | ✅ Yes — import into a spreadsheet or MySQL 8 |
| Data REST API description | `openapi.json` | ↪ Describes the API hosted by the platform (API key required) |
| App definition (pages, components, tables, permissions) | `app.json` | ↪ Canonical JSON (schema v{$doc['schemaVersion']}) for re-import or your own tooling |

Tables: {$models}
{$formsNote}
Not included on purpose: API keys, webhook signing secrets, Google tokens, payment data, end-user passwords.

## Deploy the static site
1. Upload the **contents** of `site/` to the root of a domain (e.g. Netlify, Cloudflare Pages, S3 + CloudFront, nginx).
   Paths are absolute (`/runtime/...`, `/_assets/...`), so the site must be served at the domain root.
2. Configure the host to serve `404.html` for unknown paths.
3. Test locally: `npx serve site` (or `python3 -m http.server -d site 8080`) and open http://localhost:8080.

## Load the data into MySQL
```bash
mysql -u <user> -p <database> < schema.sql
```
`select` fields are enforced with CHECK constraints; unique fields with UNIQUE keys. Date-times are stored in UTC. CSV files include a UTF-8 BOM for Excel; cells starting with `= + - @` are prefixed with `'`.

## Keep using forms and data
Keep the site published on the platform, or build your own backend from `app.json` + `openapi.json`.
MD;
    }
}
