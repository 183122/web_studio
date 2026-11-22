<?php

namespace App\Domain\Ai;

use App\Domain\Data\FieldValue;
use App\Domain\Data\RecordInput;
use App\Domain\Schema\CommandApplier;
use App\Domain\Schema\DefinitionFactory;
use App\Domain\Schema\Registry;
use App\Domain\Schema\Rules;
use App\Domain\Schema\SemanticValidator;

/**
 * Deterministic compiler: normalised plan/edit specs → allowlisted typed commands
 * (the same commands the visual editor uses). Every node gets registry defaults, every value is
 * re-checked against the canonical rules, and anything that cannot be expressed safely is skipped
 * with a warning instead of being passed through.
 */
final class AppSpecCompiler
{
    /** @var list<array> */
    private array $commands = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, array> entity name → model (existing or new) */
    private array $models = [];

    private function __construct(private readonly array $doc) {}

    // ---------------------------------------------------------------- nodes

    /** Creates a node with registry defaults; returns [id, nodes(preorder)]. */
    private static function node(string $type, array $props = [], ?array $styles = null, array $children = [], ?string $name = null): array
    {
        $def = Registry::component($type);
        $id = DefinitionFactory::newId($type === 'Page' ? 'p' : 'c');
        $values = [];
        foreach ($def['props'] as $key => $prop) {
            $values[$key] = array_key_exists($key, $props) ? $props[$key] : $prop['default'];
            if (SemanticValidator::validatePropValue($prop, $values[$key]) !== null) {
                $values[$key] = $prop['default'];
            }
        }
        $styles ??= $def['defaultStyles'];
        foreach ($styles as $bp => $map) {
            if ($map === [] || $map === null) {
                unset($styles[$bp]);
            }
        }
        $node = ['id' => $id, 'type' => $type, 'props' => $values, 'styles' => $styles, 'children' => []];
        if ($name) {
            $node['name'] = mb_substr($name, 0, 80);
        }
        $nodes = [];
        foreach ($children as [$cid, $cnodes]) {
            $node['children'][] = $cid;
            array_push($nodes, ...$cnodes);
        }

        return [$id, array_merge([$node], $nodes)];
    }

    private static function heading(string $text, string $level = 'h2', array $base = []): array
    {
        $size = ['h1' => '44px', 'h2' => '32px', 'h3' => '22px'][$level] ?? '22px';
        $mobile = ['h1' => '30px', 'h2' => '26px', 'h3' => '20px'][$level] ?? '20px';

        return self::node('Heading', ['text' => mb_substr($text, 0, 300) ?: ' ', 'level' => $level], [
            'base' => ['fontFamily' => '$heading', 'fontWeight' => '700', 'fontSize' => $size, 'lineHeight' => 1.3] + $base,
            'mobile' => ['fontSize' => $mobile],
        ]);
    }

    private static function text(string $text, array $base = []): array
    {
        return self::node('Text', ['text' => mb_substr($text, 0, 5000)], ['base' => ['fontSize' => '17px', 'lineHeight' => 1.7] + $base]);
    }

    private static function container(array $children, array $base = []): array
    {
        return self::node('Container', [], ['base' => ['display' => 'flex', 'flexDirection' => 'column', 'gap' => '16px', 'maxWidth' => '1120px', 'margin' => '0 auto', 'width' => '100%'] + $base], $children);
    }

    private static function section(array $children, array $base = [], ?string $name = null, string $anchor = ''): array
    {
        return self::node('Section', ['tag' => 'section', 'anchorId' => $anchor, 'backgroundImage' => ''], [
            'base' => ['padding' => '64px 24px'] + $base, 'mobile' => ['padding' => '40px 16px'],
        ], $children, $name);
    }

    private static function button(string $label, string $href, string $variant = 'primary'): array
    {
        return self::node('Button', ['label' => mb_substr($label, 0, 80), 'href' => Rules::isSafeHref($href) ? $href : '#', 'variant' => $variant, 'newTab' => false]);
    }

    /** Flattens a [rootId, nodes] tuple into the nodes list. */
    private static function nodes(array $tuple): array
    {
        return $tuple[1];
    }

    // ---------------------------------------------------------------- entities

    private function existingModel(string $name): ?array
    {
        foreach ($this->doc['dataModels'] as $m) {
            if ($m['name'] === $name) {
                return $m;
            }
        }

        return null;
    }

    private function compileEntity(array $e, array $permissionSpec, array $publicFormEntities): void
    {
        $existing = $this->existingModel($e['name']);
        if ($existing !== null) {
            $this->models[$e['name']] = $existing;
            $this->warnings[] = "ใช้ตารางที่มีอยู่แล้ว \"{$existing['label']}\" ({$e['name']}) แทนการสร้างใหม่";

            return;
        }
        $fields = [];
        $names = [];
        foreach ($e['fields'] as $f) {
            $name = $f['name'];
            $i = 2;
            while (isset($names[$name]) || in_array($name, FieldValue::RESERVED_NAMES, true)) {
                $name = "{$f['name']}_{$i}";
                $i++;
            }
            $names[$name] = true;
            $field = ['id' => DefinitionFactory::newId('f'), 'name' => $name, 'label' => $f['label'], 'type' => $f['type'], 'required' => $f['required'], 'unique' => $f['unique']];
            if ($f['type'] === 'select') {
                $field['options'] = $f['options'];
            }
            $fields[] = $field;
        }
        $display = collect($fields)->first(fn ($f) => $f['type'] === 'text') ?? $fields[0];
        $model = ['id' => DefinitionFactory::newId('m'), 'name' => $e['name'], 'label' => $e['label'], 'fields' => $fields, 'displayFieldId' => $display['id']];
        $this->models[$e['name']] = $model;
        $this->commands[] = ['type' => 'model.upsert', 'model' => $model, 'index' => 1000];

        $public = $permissionSpec['public'] ?? [];
        $member = $permissionSpec['member'] ?? ['read'];
        if (in_array($e['name'], $publicFormEntities, true) && ! in_array('create', $public, true)) {
            $public[] = 'create';
            $this->warnings[] = "เปิดสิทธิ์ \"สร้าง\" ให้ผู้เยี่ยมชมในตาราง {$e['label']} เพราะมีฟอร์มสาธารณะ";
        }
        $this->commands[] = ['type' => 'permission.set', 'permission' => ['modelId' => $model['id'], 'rules' => [
            'public' => array_values(array_unique($public)),
            'member' => array_values(array_unique($member)),
            'admin' => ['create', 'read', 'update', 'delete'],
        ]]];
    }

    private function fieldIds(array $model, array $names, int $limit): array
    {
        $byName = array_column($model['fields'], 'id', 'name');
        $ids = [];
        foreach ($names as $n) {
            if (isset($byName[$n]) && ! in_array($byName[$n], $ids, true)) {
                $ids[] = $byName[$n];
            }
        }
        if ($ids === []) {
            $ids = array_column($model['fields'], 'id');
        }

        return array_slice($ids, 0, $limit);
    }

    // ---------------------------------------------------------------- sections

    /** @return array{0:string,1:array}|null */
    private function compileSection(array $s): ?array
    {
        $kids = [];
        switch ($s['kind']) {
            case 'hero':
                $inner = [self::heading($s['heading'] ?: 'ยินดีต้อนรับ', 'h1', ['color' => '#ffffff'])];
                if ($s['text'] !== '') {
                    $inner[] = self::text($s['text'], ['color' => '#e2f3f1', 'fontSize' => '19px']);
                }
                if ($s['buttonLabel'] !== '') {
                    $inner[] = self::node('Container', [], ['base' => ['display' => 'flex', 'flexDirection' => 'row', 'flexWrap' => 'wrap', 'gap' => '12px']], [self::button($s['buttonLabel'], $s['buttonHref'] ?: '#', 'secondary')]);
                }

                return self::section([self::container($inner, ['maxWidth' => '880px'])], ['backgroundColor' => '$primary', 'padding' => '96px 24px'], 'Hero');
            case 'features':
                $cards = array_map(fn ($i) => self::node('Card', [], null, array_values(array_filter([
                    $i['title'] !== '' ? self::heading($i['title'], 'h3') : null,
                    $i['text'] !== '' ? self::text($i['text']) : null,
                ]))), $s['items'] ?: [['title' => $s['heading'], 'text' => $s['text']]]);
                $cols = self::node('Columns', [], ['base' => ['display' => 'grid', 'columns' => min(3, max(1, count($cards))), 'gap' => '24px'], 'tablet' => ['columns' => min(2, count($cards))], 'mobile' => ['columns' => 1]], $cards);
                $kids = array_values(array_filter([$s['heading'] !== '' ? self::heading($s['heading'], 'h2', ['textAlign' => 'center']) : null, $cols]));

                return self::section([self::container($kids)], [], 'Features');
            case 'text':
            case 'contact':
                $kids = array_values(array_filter([
                    $s['heading'] !== '' ? self::heading($s['heading']) : null,
                    $s['text'] !== '' ? self::text($s['text']) : null,
                    $s['buttonLabel'] !== '' ? self::button($s['buttonLabel'], $s['buttonHref'] ?: '#', 'outline') : null,
                ]));
                if ($kids === []) {
                    return null;
                }

                return self::section([self::container($kids, ['maxWidth' => '880px'])], [], $s['kind'] === 'contact' ? 'Contact' : 'Text', $s['kind'] === 'contact' ? 'contact' : '');
            case 'cta':
                $kids = array_values(array_filter([
                    self::heading($s['heading'] ?: 'พร้อมเริ่มต้นแล้วหรือยัง?', 'h2', ['textAlign' => 'center']),
                    $s['text'] !== '' ? self::text($s['text'], ['textAlign' => 'center']) : null,
                    $s['buttonLabel'] !== '' ? self::button($s['buttonLabel'], $s['buttonHref'] ?: '#') : null,
                ]));

                return self::section([self::container($kids, ['maxWidth' => '760px', 'alignItems' => 'center'])], ['backgroundColor' => '$surface'], 'CTA');
            case 'faq':
                $kids = [self::heading($s['heading'] ?: 'คำถามที่พบบ่อย')];
                foreach ($s['items'] as $i) {
                    $kids[] = self::node('Card', [], null, array_values(array_filter([$i['title'] !== '' ? self::heading($i['title'], 'h3') : null, $i['text'] !== '' ? self::text($i['text']) : null])));
                }

                return self::section([self::container($kids, ['maxWidth' => '880px'])], [], 'FAQ');
            case 'list':
                $items = array_values(array_filter(array_map(fn ($i) => mb_substr(trim($i['title'].($i['text'] !== '' ? ' — '.$i['text'] : '')), 0, 500), $s['items'])));
                $kids = array_values(array_filter([
                    $s['heading'] !== '' ? self::heading($s['heading']) : null,
                    $s['text'] !== '' ? self::text($s['text']) : null,
                    $items !== [] ? self::node('List', ['items' => $items, 'ordered' => false]) : null,
                ]));

                return $kids === [] ? null : self::section([self::container($kids, ['maxWidth' => '880px'])], [], 'List');
            case 'form':
                $model = $this->models[$s['entity']] ?? null;
                if ($model === null) {
                    $this->warnings[] = "ข้ามส่วนฟอร์มที่อ้างถึงตาราง \"{$s['entity']}\" ซึ่งไม่มีอยู่";

                    return null;
                }
                $fieldNodes = array_map(fn ($fid) => self::node('FormField', ['fieldId' => $fid]), $this->fieldIds($model, $s['fields'], 20));
                $form = self::node('Form', ['modelId' => $model['id'], 'submitLabel' => mb_substr($s['buttonLabel'] ?: 'ส่งข้อมูล', 0, 60)], null, $fieldNodes);
                $kids = array_values(array_filter([
                    $s['heading'] !== '' ? self::heading($s['heading']) : null,
                    $s['text'] !== '' ? self::text($s['text']) : null,
                    $form,
                ]));

                return self::section([self::container($kids, ['maxWidth' => '760px'])], [], 'Form');
            case 'table':
                $model = $this->models[$s['entity']] ?? null;
                if ($model === null) {
                    $this->warnings[] = "ข้ามตารางแสดงข้อมูลที่อ้างถึง \"{$s['entity']}\" ซึ่งไม่มีอยู่";

                    return null;
                }
                $table = self::node('DataTable', ['modelId' => $model['id'], 'fieldIds' => $this->fieldIds($model, $s['fields'], 6), 'pageSize' => '10', 'searchable' => true]);
                $kids = array_values(array_filter([$s['heading'] !== '' ? self::heading($s['heading']) : null, $s['text'] !== '' ? self::text($s['text'], ['color' => '$muted']) : null, $table]));

                return self::section([self::container($kids)], [], 'Table');
        }

        return null;
    }

    // ---------------------------------------------------------------- plan

    /**
     * @return array{commands: list<array>, summary: array}
     */
    public static function compilePlan(array $doc, array $plan): array
    {
        $c = new self($doc);
        $summary = ['pagesAdded' => [], 'pagesUpdated' => [], 'modelsAdded' => [], 'modelsReused' => [], 'workflowsAdded' => [], 'permissions' => [], 'themeChanged' => false];

        // Site + theme
        if ($plan['siteName'] !== '') {
            $c->commands[] = ['type' => 'site.update', 'changes' => ['name' => $plan['siteName'], 'seo' => ['siteName' => $plan['siteName'], 'description' => mb_substr($plan['summary'], 0, 300)]]];
        }
        $colors = array_filter(['primary' => $plan['theme']['primary'], 'secondary' => $plan['theme']['secondary'], 'accent' => $plan['theme']['accent']]);
        $fonts = array_filter(['heading' => $plan['theme']['headingFont'], 'body' => $plan['theme']['bodyFont']]);
        if ($colors !== [] || $fonts !== []) {
            $c->commands[] = ['type' => 'tokens.update', 'changes' => array_filter(['colors' => $colors ?: null, 'fonts' => $fonts ?: null])];
            $summary['themeChanged'] = true;
        }

        // Entities (+ explicit, default-deny permissions)
        $publicForms = [];
        foreach ($plan['pages'] as $p) {
            foreach ($p['sections'] as $s) {
                if ($s['kind'] === 'form' && $s['entity'] !== '') {
                    $publicForms[] = $s['entity'];
                }
            }
        }
        $permByEntity = [];
        foreach ($plan['permissions'] as $p) {
            $permByEntity[$p['entity']] = $p;
        }
        foreach ($plan['entities'] as $e) {
            $c->compileEntity($e, $permByEntity[$e['name']] ?? [], $publicForms);
        }
        foreach ($c->commands as $cmd) {
            if ($cmd['type'] === 'model.upsert') {
                $summary['modelsAdded'][] = ['name' => $cmd['model']['name'], 'label' => $cmd['model']['label'], 'fields' => array_map(fn ($f) => "{$f['label']} ({$f['type']}".($f['required'] ? ', จำเป็น' : '').')', $cmd['model']['fields'])];
            }
            if ($cmd['type'] === 'permission.set') {
                $label = collect($c->models)->firstWhere('id', $cmd['permission']['modelId'])['label'] ?? '';
                $summary['permissions'][] = ['model' => $label] + $cmd['permission']['rules'];
            }
        }
        foreach ($c->models as $name => $m) {
            if ($c->existingModel($name) !== null) {
                $summary['modelsReused'][] = $m['label'];
            }
        }

        // Pages
        $existingPaths = array_column($doc['pages'], 'path');
        $planPages = [];
        foreach ($plan['pages'] as $i => $p) {
            $path = $p['path'] ?: '/page-'.($i + 1);
            if ($path !== '/') {
                $base = $path;
                $n = 2;
                while (in_array($path, $existingPaths, true)) {
                    $path = "{$base}-{$n}";
                    $n++;
                }
                $existingPaths[] = $path;
            }
            $planPages[] = ['path' => $path] + $p;
        }
        $links = array_slice(array_map(fn ($p) => ['label' => mb_substr($p['name'], 0, 80), 'href' => $p['path']], $planPages), 0, 6);
        $siteName = $plan['siteName'] ?: $doc['name'];
        $navbar = fn () => self::node('Navbar', ['brand' => mb_substr($siteName, 0, 80), 'links' => $links], ['base' => ['padding' => '16px 24px', 'backgroundColor' => '$surface']]);
        $footer = fn () => self::node('Section', ['tag' => 'footer', 'anchorId' => '', 'backgroundImage' => ''], ['base' => ['padding' => '32px 24px', 'backgroundColor' => '$secondary', 'color' => '#ffffff']], [self::container([self::text('© '.$siteName, ['fontSize' => '14px', 'textAlign' => 'center'])])]);

        $home = collect($doc['pages'])->firstWhere('id', $doc['settings']['homePageId']);
        $homeUsed = false;
        foreach ($planPages as $p) {
            $sections = array_values(array_filter(array_map(fn ($s) => $c->compileSection($s), $p['sections'])));
            $seo = ['title' => mb_substr($p['title'] ?: $p['name'], 0, 120), 'description' => mb_substr($p['description'], 0, 320), 'noindex' => false];
            if ($p['path'] === '/' && ! $homeUsed) {
                $homeUsed = true;
                $root = $doc['components'][$home['rootId']];
                $index = count($root['children']);
                $blocks = $root['children'] === [] ? array_merge([$navbar()], $sections, [$footer()]) : $sections;
                foreach ($blocks as [$id, $nodes]) {
                    $c->commands[] = ['type' => 'component.insert', 'parentId' => $root['id'], 'index' => $index++, 'nodes' => $nodes];
                }
                $c->commands[] = ['type' => 'page.update', 'id' => $home['id'], 'changes' => ['name' => $p['name'], 'seo' => $seo]];
                $summary['pagesUpdated'][] = ['name' => $p['name'], 'path' => '/', 'sections' => array_column($p['sections'], 'kind')];

                continue;
            }
            $path = $p['path'] === '/' ? '/home-'.substr(md5($p['name']), 0, 4) : $p['path'];
            [$rootId, $rootNodes] = self::node('Page', [], null, array_merge([$navbar()], $sections, [$footer()]));
            $pageId = DefinitionFactory::newId('pg');
            $c->commands[] = ['type' => 'page.add', 'index' => 1000, 'page' => ['id' => $pageId, 'name' => $p['name'], 'path' => $path, 'rootId' => $rootId, 'seo' => $seo], 'nodes' => $rootNodes];
            $summary['pagesAdded'][] = ['name' => $p['name'], 'path' => $path, 'sections' => array_column($p['sections'], 'kind')];
        }

        // Workflows
        foreach ($plan['workflows'] as $w) {
            $model = $c->models[$w['entity']] ?? null;
            if ($model === null) {
                $c->warnings[] = "ข้าม workflow \"{$w['name']}\" เพราะไม่พบตาราง {$w['entity']}";

                continue;
            }
            $byName = [];
            foreach ($model['fields'] as $f) {
                $byName[$f['name']] = $f;
            }
            $actions = [];
            if ($w['setFieldName'] !== '' && isset($byName[$w['setFieldName']])) {
                $field = $byName[$w['setFieldName']];
                $value = RecordInput::normalize($field, $w['setFieldValue']);
                if (FieldValue::validate(['required' => false] + $field, $value) === null) {
                    $actions[] = ['type' => 'record.setField', 'fieldId' => $field['id'], 'value' => $value];
                } else {
                    $c->warnings[] = "workflow \"{$w['name']}\": ค่า \"{$w['setFieldValue']}\" ใช้กับฟิลด์ {$field['label']} ไม่ได้";
                }
            }
            if ($w['emailTo'] !== '' && $w['emailSubject'] !== '' && $w['emailBody'] !== '') {
                $to = null;
                if (str_starts_with($w['emailTo'], 'field:')) {
                    $f = $byName[SpecNormalizer::identifier(substr($w['emailTo'], 6), '')] ?? null;
                    $to = $f && $f['type'] === 'email' ? 'field:'.$f['id'] : null;
                } elseif (preg_match(FieldValue::EMAIL, $w['emailTo']) === 1) {
                    $to = $w['emailTo'];
                } else {
                    $emailField = collect($model['fields'])->firstWhere('type', 'email');
                    $to = $emailField ? 'field:'.$emailField['id'] : null;
                }
                // Keep only placeholders that name real fields.
                $strip = fn (string $t) => preg_replace_callback('/\{\{\s*([a-z][a-z0-9_]{0,39})\s*\}\}/', fn ($m) => isset($byName[$m[1]]) ? '{{'.$m[1].'}}' : '', $t) ?? $t;
                if ($to !== null) {
                    $actions[] = ['type' => 'email.send', 'to' => $to, 'subject' => $strip($w['emailSubject']), 'body' => $strip($w['emailBody'])];
                } else {
                    $c->warnings[] = "workflow \"{$w['name']}\": ไม่มีผู้รับอีเมลที่ถูกต้อง จึงไม่ส่งอีเมล";
                }
            }
            if ($actions === []) {
                continue;
            }
            $c->commands[] = ['type' => 'workflow.upsert', 'index' => 1000, 'workflow' => [
                'id' => DefinitionFactory::newId('w'), 'name' => $w['name'], 'enabled' => true,
                'trigger' => ['event' => $w['event'], 'modelId' => $model['id']], 'conditions' => [], 'actions' => $actions,
            ]];
            $summary['workflowsAdded'][] = ['name' => $w['name'], 'model' => $model['label'], 'event' => $w['event'], 'actions' => array_column($actions, 'type')];
        }

        $summary['warnings'] = $c->warnings;

        return ['commands' => $c->commands, 'summary' => $summary];
    }

    // ---------------------------------------------------------------- edit

    /**
     * @param  array{pageId?:string, componentId?:string}  $scope  edits outside the scope are rejected
     * @return array{commands: list<array>, summary: array}
     */
    public static function compileEdit(array $doc, array $edit, array $scope): array
    {
        $c = new self($doc);
        foreach ($doc['dataModels'] as $m) {
            $c->models[$m['name']] = $m;
        }
        $allowed = null;
        if (! empty($scope['componentId']) && isset($doc['components'][$scope['componentId']])) {
            $allowed = array_column(CommandApplier::collectSubtree($doc, $scope['componentId']), 'id');
        } elseif (! empty($scope['pageId'])) {
            $page = collect($doc['pages'])->firstWhere('id', $scope['pageId']);
            $allowed = $page ? array_column(CommandApplier::collectSubtree($doc, $page['rootId']), 'id') : null;
        }
        $inScope = fn (string $id) => $allowed === null || in_array($id, $allowed, true);
        $described = [];
        $removed = [];

        foreach ($edit['operations'] as $op) {
            $target = $doc['components'][$op['targetId']] ?? null;
            switch ($op['op']) {
                case 'setText':
                    $def = $target ? Registry::component($target['type'])['props'][$op['key']] ?? null : null;
                    if (! $target || ! $def || ! $inScope($target['id']) || ! in_array($def['kind'], ['text', 'longtext', 'url', 'select'], true)) {
                        $c->warnings[] = "ข้ามการแก้ข้อความ ({$op['targetId']}.{$op['key']})";
                        break;
                    }
                    $value = $def['kind'] === 'text' ? SpecNormalizer::text($op['value'], $def['maxLength'] ?? 300) : $op['value'];
                    if (SemanticValidator::validatePropValue($def, $value) !== null) {
                        $c->warnings[] = "ค่าใหม่ของ {$op['key']} ไม่ผ่านการตรวจสอบความปลอดภัย จึงไม่ใช้";
                        break;
                    }
                    $c->commands[] = ['type' => 'component.update', 'id' => $target['id'], 'props' => [$op['key'] => $value]];
                    $described[] = ['op' => 'setText', 'target' => $target['id'], 'type' => $target['type'], 'key' => $op['key'], 'before' => $target['props'][$op['key']] ?? null, 'after' => $value];
                    break;
                case 'setStyle':
                    if (! $target || ! $inScope($target['id']) || SemanticValidator::validateStyleValue($op['key'], is_numeric($op['value']) ? $op['value'] + 0 : $op['value']) !== null) {
                        $c->warnings[] = "ข้ามการแก้สไตล์ {$op['key']}";
                        break;
                    }
                    $value = is_numeric($op['value']) && in_array(Registry::styleProperty($op['key'])['kind'], ['integer', 'number'], true) ? $op['value'] + 0 : $op['value'];
                    $c->commands[] = ['type' => 'component.update', 'id' => $target['id'], 'styles' => [$op['breakpoint'] => [$op['key'] => $value]]];
                    $described[] = ['op' => 'setStyle', 'target' => $target['id'], 'type' => $target['type'], 'key' => "{$op['breakpoint']}.{$op['key']}", 'before' => $target['styles'][$op['breakpoint']][$op['key']] ?? null, 'after' => $value];
                    break;
                case 'setTheme':
                    $hex = SpecNormalizer::hex($op['value']);
                    if (! in_array($op['key'], ['primary', 'secondary', 'accent', 'background', 'surface', 'text', 'muted'], true) || $hex === null) {
                        $c->warnings[] = 'ข้ามการเปลี่ยนสีธีมที่ไม่ถูกต้อง';
                        break;
                    }
                    $c->commands[] = ['type' => 'tokens.update', 'changes' => ['colors' => [$op['key'] => $hex]]];
                    $described[] = ['op' => 'setTheme', 'key' => $op['key'], 'before' => $doc['designTokens']['colors'][$op['key']], 'after' => $hex];
                    break;
                case 'addSection':
                    $pageId = $scope['pageId'] ?? $doc['settings']['homePageId'];
                    $page = collect($doc['pages'])->firstWhere('id', $pageId);
                    if (! $page || ! $op['section']) {
                        $c->warnings[] = 'ข้ามการเพิ่มส่วน (ไม่พบหน้า)';
                        break;
                    }
                    $tuple = $c->compileSection($op['section']);
                    if ($tuple === null) {
                        break;
                    }
                    $root = $doc['components'][$page['rootId']];
                    $index = count($root['children']);
                    $after = array_search($op['targetId'], $root['children'], true);
                    if ($after !== false) {
                        $index = $after + 1;
                    } elseif ($root['children'] !== [] && ($doc['components'][end($root['children'])]['props']['tag'] ?? null) === 'footer') {
                        $index = count($root['children']) - 1; // keep the footer last
                    }
                    $c->commands[] = ['type' => 'component.insert', 'parentId' => $root['id'], 'index' => $index, 'nodes' => $tuple[1]];
                    $described[] = ['op' => 'addSection', 'kind' => $op['section']['kind'], 'page' => $page['name'], 'heading' => $op['section']['heading']];
                    break;
                case 'removeComponent':
                    if (! $target || $target['type'] === 'Page' || ! $inScope($target['id']) || in_array($target['id'], $removed, true)) {
                        $c->warnings[] = 'ข้ามการลบคอมโพเนนต์';
                        break;
                    }
                    $removed = array_merge($removed, array_column(CommandApplier::collectSubtree($doc, $target['id']), 'id'));
                    $c->commands[] = ['type' => 'component.remove', 'id' => $target['id']];
                    $described[] = ['op' => 'removeComponent', 'target' => $target['id'], 'type' => $target['type']];
                    break;
                case 'addField':
                    $model = $c->models[SpecNormalizer::identifier($op['key'], '')] ?? null;
                    if (! $model || ! $op['field']) {
                        $c->warnings[] = 'ข้ามการเพิ่มฟิลด์ (ไม่พบตาราง)';
                        break;
                    }
                    $f = $op['field'];
                    if (in_array($f['name'], array_column($model['fields'], 'name'), true) || in_array($f['name'], FieldValue::RESERVED_NAMES, true)) {
                        $c->warnings[] = "ฟิลด์ {$f['name']} มีอยู่แล้ว";
                        break;
                    }
                    $field = ['id' => DefinitionFactory::newId('f'), 'name' => $f['name'], 'label' => $f['label'], 'type' => $f['type'], 'required' => false, 'unique' => false];
                    if ($f['type'] === 'select') {
                        $field['options'] = $f['options'];
                    }
                    $model['fields'][] = $field;
                    $c->models[$model['name']] = $model;
                    $c->commands[] = ['type' => 'model.upsert', 'model' => $model, 'index' => 1000];
                    $described[] = ['op' => 'addField', 'model' => $model['label'], 'field' => "{$field['label']} ({$field['type']})", 'note' => 'เพิ่มเป็นฟิลด์ไม่บังคับ เพื่อไม่ให้ข้อมูลเดิมผิดเงื่อนไข'];
                    break;
                case 'renamePage':
                    $page = collect($doc['pages'])->firstWhere('id', $op['targetId'] ?: ($scope['pageId'] ?? ''));
                    $name = SpecNormalizer::text($op['value'], 80);
                    if (! $page || $name === '') {
                        $c->warnings[] = 'ข้ามการเปลี่ยนชื่อหน้า';
                        break;
                    }
                    $c->commands[] = ['type' => 'page.update', 'id' => $page['id'], 'changes' => ['name' => $name]];
                    $described[] = ['op' => 'renamePage', 'before' => $page['name'], 'after' => $name];
                    break;
            }
        }

        return ['commands' => $c->commands, 'summary' => ['changes' => $described, 'warnings' => $c->warnings]];
    }
}
