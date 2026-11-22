<?php

namespace App\Domain\Ai;

use App\Domain\Schema\Rules;
use App\Support\ApiException;

/**
 * Treats model output as untrusted: coerces shapes, clamps counts and lengths, strips control
 * characters, and drops anything outside the vocabulary. Never throws for content problems
 * (the compiler + canonical validator are the hard gate); throws only for non-object output.
 */
final class SpecNormalizer
{
    public const MAX_PAGES = 8;

    public const MAX_SECTIONS = 10;

    public const MAX_ITEMS = 8;

    public const MAX_ENTITIES = 6;

    public const MAX_FIELDS = 20;

    public const MAX_WORKFLOWS = 6;

    public const MAX_OPERATIONS = 20;

    public static function text(mixed $v, int $max, bool $multiline = false): string
    {
        if (! is_scalar($v)) {
            return '';
        }
        $s = (string) $v;
        if (! mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }
        $s = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}]/u', '', $s) ?? '';
        if (! $multiline) {
            $s = preg_replace('/\s*[\r\n]+\s*/u', ' ', $s) ?? '';
        }
        $s = trim($s);

        return mb_substr($s, 0, $max);
    }

    public static function list(mixed $v, int $max): array
    {
        return is_array($v) ? array_slice(array_values($v), 0, $max) : [];
    }

    public static function identifier(mixed $v, string $fallback): string
    {
        $s = strtolower(self::text($v, 60));
        $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?? '';
        $s = trim(preg_replace('/^[^a-z]+/', '', $s) ?? '', '_');
        $s = substr($s, 0, 36);

        return $s !== '' ? $s : $fallback;
    }

    public static function path(mixed $v): string
    {
        $s = strtolower(self::text($v, 120));
        if ($s === '' || $s === '/') {
            return '/';
        }
        $parts = array_filter(array_map(fn ($p) => trim(preg_replace('/[^a-z0-9]+/', '-', $p) ?? '', '-'), explode('/', $s)));

        return $parts === [] ? '' : '/'.implode('/', array_slice(array_values($parts), 0, 3));
    }

    public static function href(mixed $v): string
    {
        $s = self::text($v, 300);

        return Rules::isSafeHref($s) ? $s : '';
    }

    public static function hex(mixed $v): ?string
    {
        $s = self::text($v, 9);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $s) === 1 ? strtolower($s) : null;
    }

    private static function section(mixed $s): ?array
    {
        if (! is_array($s) || ! in_array($s['kind'] ?? null, Specs::SECTION_KINDS, true)) {
            return null;
        }

        return [
            'kind' => $s['kind'],
            'heading' => self::text($s['heading'] ?? '', 200),
            'text' => self::text($s['text'] ?? '', 1500, true),
            'items' => array_values(array_filter(array_map(fn ($i) => is_array($i) ? [
                'title' => self::text($i['title'] ?? '', 120),
                'text' => self::text($i['text'] ?? '', 600, true),
            ] : null, self::list($s['items'] ?? [], self::MAX_ITEMS)), fn ($i) => $i !== null && ($i['title'] !== '' || $i['text'] !== ''))),
            'buttonLabel' => self::text($s['buttonLabel'] ?? '', 60),
            'buttonHref' => self::href($s['buttonHref'] ?? ''),
            'entity' => self::identifier($s['entity'] ?? '', ''),
            'fields' => array_values(array_filter(array_map(fn ($f) => self::identifier($f, ''), self::list($s['fields'] ?? [], self::MAX_FIELDS)))),
        ];
    }

    public static function field(mixed $f, int $n): ?array
    {
        if (! is_array($f)) {
            return null;
        }
        $type = in_array($f['type'] ?? null, Specs::FIELD_TYPES, true) ? $f['type'] : 'text';
        $options = array_values(array_unique(array_filter(array_map(fn ($o) => self::text($o, 100), self::list($f['options'] ?? [], 50)))));
        if ($type === 'select' && $options === []) {
            $type = 'text';
        }

        return [
            'name' => self::identifier($f['name'] ?? '', "field_{$n}"),
            'label' => self::text($f['label'] ?? '', 80) ?: "ฟิลด์ {$n}",
            'type' => $type,
            'required' => ($f['required'] ?? false) === true,
            'unique' => ($f['unique'] ?? false) === true && ! in_array($type, ['longtext', 'boolean'], true),
            'options' => $type === 'select' ? $options : [],
        ];
    }

    public static function plan(mixed $raw): array
    {
        if (! is_array($raw) || array_is_list($raw)) {
            throw ApiException::unprocessable('ai_invalid_output', 'AI ตอบกลับในรูปแบบที่ไม่ถูกต้อง');
        }
        $theme = is_array($raw['theme'] ?? null) ? $raw['theme'] : [];
        $pages = [];
        foreach (self::list($raw['pages'] ?? [], self::MAX_PAGES) as $i => $p) {
            if (! is_array($p)) {
                continue;
            }
            $pages[] = [
                'name' => self::text($p['name'] ?? '', 80) ?: 'หน้า '.($i + 1),
                'path' => self::path($p['path'] ?? ''),
                'title' => self::text($p['title'] ?? '', 120),
                'description' => self::text($p['description'] ?? '', 300),
                'sections' => array_values(array_filter(array_map([self::class, 'section'], self::list($p['sections'] ?? [], self::MAX_SECTIONS)))),
            ];
        }
        $entities = [];
        foreach (self::list($raw['entities'] ?? [], self::MAX_ENTITIES) as $i => $e) {
            if (! is_array($e)) {
                continue;
            }
            $fields = [];
            foreach (self::list($e['fields'] ?? [], self::MAX_FIELDS) as $j => $f) {
                $nf = self::field($f, $j + 1);
                if ($nf !== null) {
                    $fields[] = $nf;
                }
            }
            if ($fields === []) {
                continue;
            }
            $entities[] = [
                'name' => self::identifier($e['name'] ?? '', 'table_'.($i + 1)),
                'label' => self::text($e['label'] ?? '', 80) ?: 'ตาราง '.($i + 1),
                'fields' => $fields,
            ];
        }
        $acts = fn ($a) => array_values(array_intersect(['create', 'read', 'update', 'delete'], is_array($a) ? $a : []));

        return [
            'summary' => self::text($raw['summary'] ?? '', 1000, true),
            'confidence' => max(0.0, min(1.0, (float) (is_numeric($raw['confidence'] ?? null) ? $raw['confidence'] : 0.5))),
            'missingRequirements' => array_values(array_filter(array_map(fn ($s) => self::text($s, 300), self::list($raw['missingRequirements'] ?? [], 10)))),
            'assumptions' => array_values(array_filter(array_map(fn ($s) => self::text($s, 300), self::list($raw['assumptions'] ?? [], 10)))),
            'siteName' => self::text($raw['siteName'] ?? '', 120),
            'theme' => [
                'primary' => self::hex($theme['primary'] ?? null),
                'secondary' => self::hex($theme['secondary'] ?? null),
                'accent' => self::hex($theme['accent'] ?? null),
                'headingFont' => in_array($theme['headingFont'] ?? null, Specs::FONTS, true) ? $theme['headingFont'] : null,
                'bodyFont' => in_array($theme['bodyFont'] ?? null, Specs::FONTS, true) ? $theme['bodyFont'] : null,
            ],
            'pages' => $pages,
            'entities' => $entities,
            'permissions' => array_values(array_filter(array_map(fn ($p) => is_array($p) ? [
                'entity' => self::identifier($p['entity'] ?? '', ''),
                'public' => $acts($p['public'] ?? []),
                'member' => $acts($p['member'] ?? []),
            ] : null, self::list($raw['permissions'] ?? [], self::MAX_ENTITIES)))),
            'workflows' => array_values(array_filter(array_map(fn ($w) => is_array($w) ? [
                'name' => self::text($w['name'] ?? '', 80) ?: 'workflow',
                'entity' => self::identifier($w['entity'] ?? '', ''),
                'event' => ($w['event'] ?? '') === 'record.updated' ? 'record.updated' : 'record.created',
                'setFieldName' => self::identifier($w['setFieldName'] ?? '', ''),
                'setFieldValue' => self::text($w['setFieldValue'] ?? '', 200),
                'emailTo' => self::text($w['emailTo'] ?? '', 254),
                'emailSubject' => self::text($w['emailSubject'] ?? '', 150),
                'emailBody' => self::text($w['emailBody'] ?? '', 3000, true),
            ] : null, self::list($raw['workflows'] ?? [], self::MAX_WORKFLOWS)))),
        ];
    }

    public static function edit(mixed $raw): array
    {
        if (! is_array($raw) || array_is_list($raw)) {
            throw ApiException::unprocessable('ai_invalid_output', 'AI ตอบกลับในรูปแบบที่ไม่ถูกต้อง');
        }
        $ops = [];
        foreach (self::list($raw['operations'] ?? [], self::MAX_OPERATIONS) as $o) {
            if (! is_array($o) || ! in_array($o['op'] ?? null, Specs::EDIT_OPS, true)) {
                continue;
            }
            $ops[] = [
                'op' => $o['op'],
                'targetId' => self::text($o['targetId'] ?? '', 64),
                'key' => self::text($o['key'] ?? '', 40),
                'value' => self::text($o['value'] ?? '', 5000, true),
                'breakpoint' => in_array($o['breakpoint'] ?? null, ['base', 'tablet', 'mobile'], true) ? $o['breakpoint'] : 'base',
                'section' => isset($o['section']) ? self::section($o['section']) : null,
                'field' => isset($o['field']) ? self::field($o['field'], 1) : null,
            ];
        }

        return ['summary' => self::text($raw['summary'] ?? '', 1000, true), 'operations' => $ops];
    }
}
