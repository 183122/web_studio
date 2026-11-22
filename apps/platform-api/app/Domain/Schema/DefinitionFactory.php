<?php

namespace App\Domain\Schema;

use Illuminate\Support\Str;

/** Server-side creation of new documents (blank or from a template). */
final class DefinitionFactory
{
    public static function newId(string $prefix): string
    {
        return $prefix.'_'.Str::random(10);
    }

    public static function blank(string $projectId, string $name): array
    {
        $rootId = self::newId('p');
        $pageId = self::newId('pg');

        return [
            'schemaVersion' => 2,
            'projectId' => $projectId,
            'name' => $name,
            'pages' => [[
                'id' => $pageId,
                'name' => 'หน้าแรก',
                'path' => '/',
                'rootId' => $rootId,
                'seo' => ['title' => $name, 'description' => '', 'noindex' => false],
            ]],
            'routes' => [],
            'components' => [
                $rootId => ['id' => $rootId, 'type' => 'Page', 'props' => [], 'styles' => [], 'children' => []],
            ],
            'designTokens' => [
                'colors' => [
                    'primary' => '#0f766e', 'secondary' => '#1e3a5f', 'accent' => '#f59e0b',
                    'background' => '#ffffff', 'surface' => '#f8fafc', 'text' => '#0f172a', 'muted' => '#64748b',
                ],
                'fonts' => ['heading' => 'Kanit', 'body' => 'Sarabun'],
                'radius' => '12px',
            ],
            'dataModels' => [],
            'permissions' => [],
            'actions' => [],
            'workflows' => [],
            'integrations' => [],
            'assets' => [],
            'seo' => ['siteName' => $name, 'description' => '', 'lang' => 'th'],
            'settings' => ['homePageId' => $pageId, 'notFoundPageId' => null],
        ];
    }

    /** Re-target a template/cloned document to a new project. Template assets are not copied across tenants. */
    public static function fromTemplate(array $templateDoc, string $projectId, string $name): array
    {
        $doc = $templateDoc;
        $doc['projectId'] = $projectId;
        $doc['name'] = $name;
        $doc['seo']['siteName'] = $name;

        return $doc;
    }
}
