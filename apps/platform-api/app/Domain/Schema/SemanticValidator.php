<?php

namespace App\Domain\Schema;

/**
 * Mirror of packages/app-schema/src/semantic.ts. Assumes structural validation passed.
 */
final class SemanticValidator
{
    /** @return list<array{path:string,code:string,message:string}> */
    public static function validate(array $doc): array
    {
        $issues = [];
        $add = static function (string $path, string $code, string $message) use (&$issues): void {
            $issues[] = ['path' => $path, 'code' => $code, 'message' => $message];
        };

        $components = $doc['components'];
        $componentIds = array_map('strval', array_keys($components));
        if (count($componentIds) > Rules::MAX_COMPONENTS) {
            $add('/components', 'too_many_components', 'at most '.Rules::MAX_COMPONENTS.' components');
        }

        $parentOf = [];
        foreach ($componentIds as $id) {
            $node = $components[$id];
            $base = "/components/{$id}";
            if ($node['id'] !== $id) {
                $add("{$base}/id", 'id_mismatch', 'component id must equal its key');
            }
            $def = Registry::component($node['type']);
            if ($def === null) {
                $add("{$base}/type", 'unknown_component', "component type \"{$node['type']}\" is not allowed");

                continue;
            }
            foreach ($node['props'] as $key => $value) {
                $key = (string) $key;
                if (! array_key_exists($key, $def['props'])) {
                    $add("{$base}/props/{$key}", 'unknown_prop', "prop \"{$key}\" is not allowed on {$node['type']}");

                    continue;
                }
                $err = self::validatePropValue($def['props'][$key], $value);
                if ($err !== null) {
                    $add("{$base}/props/{$key}", 'invalid_prop', "{$key} {$err}");
                }
            }
            foreach ($node['styles'] as $bp => $map) {
                if ($map === [] || $map === null) {
                    $add("{$base}/styles/{$bp}", 'empty_style_map', 'empty breakpoint style maps must be omitted');

                    continue;
                }
                foreach ($map as $prop => $value) {
                    $err = self::validateStyleValue((string) $prop, $value);
                    if ($err !== null) {
                        $add("{$base}/styles/{$bp}/{$prop}", 'invalid_style', "{$prop} {$err}");
                    }
                }
            }
            if (! $def['acceptsChildren'] && count($node['children']) > 0) {
                $add("{$base}/children", 'children_not_allowed', "{$node['type']} cannot contain children");
            }
            foreach ($node['children'] as $i => $childId) {
                if (! array_key_exists($childId, $components)) {
                    $add("{$base}/children/{$i}", 'missing_child', "child \"{$childId}\" does not exist");

                    continue;
                }
                if ($components[$childId]['type'] === 'Page') {
                    $add("{$base}/children/{$i}", 'invalid_child', 'a Page cannot be nested');
                }
                if (array_key_exists($childId, $parentOf)) {
                    $add("{$base}/children/{$i}", 'multiple_parents', "component \"{$childId}\" has more than one parent");
                } else {
                    $parentOf[$childId] = $id;
                }
            }
        }

        $pageIds = [];
        $paths = [];
        $roots = [];
        if (count($doc['pages']) > Rules::MAX_PAGES) {
            $add('/pages', 'too_many_pages', 'at most '.Rules::MAX_PAGES.' pages');
        }
        foreach ($doc['pages'] as $i => $page) {
            $base = "/pages/{$i}";
            if (isset($pageIds[$page['id']])) {
                $add("{$base}/id", 'duplicate_page_id', 'duplicate page id');
            }
            $pageIds[$page['id']] = true;
            if (isset($paths[$page['path']])) {
                $add("{$base}/path", 'route_conflict', "path \"{$page['path']}\" is used by more than one page");
            }
            $paths[$page['path']] = true;
            $root = $components[$page['rootId']] ?? null;
            if ($root === null) {
                $add("{$base}/rootId", 'missing_root', 'page root component does not exist');
            } elseif ($root['type'] !== 'Page') {
                $add("{$base}/rootId", 'invalid_root', 'page root must be a Page component');
            }
            if (array_key_exists($page['rootId'], $parentOf)) {
                $add("{$base}/rootId", 'invalid_root', 'page root cannot be a child');
            }
            if (isset($roots[$page['rootId']])) {
                $add("{$base}/rootId", 'shared_root', 'pages cannot share a root');
            }
            $roots[$page['rootId']] = true;
        }
        foreach ($componentIds as $id) {
            if ($components[$id]['type'] === 'Page' && ! isset($roots[$id])) {
                $add("/components/{$id}", 'orphan_page_root', 'Page component is not attached to a page');
            }
        }

        if (! isset($pageIds[$doc['settings']['homePageId']])) {
            $add('/settings/homePageId', 'missing_home', 'home page does not exist');
        }
        $nf = $doc['settings']['notFoundPageId'] ?? null;
        if ($nf !== null && $nf !== '' && ! isset($pageIds[$nf])) {
            $add('/settings/notFoundPageId', 'missing_page', '404 page does not exist');
        }

        $froms = [];
        $redirectIds = [];
        foreach ($doc['routes'] as $i => $r) {
            if (isset($redirectIds[$r['id']])) {
                $add("/routes/{$i}/id", 'duplicate_route_id', 'duplicate redirect id');
            }
            $redirectIds[$r['id']] = true;
            if (isset($froms[$r['from']]) || isset($paths[$r['from']])) {
                $add("/routes/{$i}/from", 'route_conflict', "redirect source \"{$r['from']}\" conflicts with a page or redirect");
            }
            $froms[$r['from']] = true;
        }

        $visited = [];
        foreach ($doc['pages'] as $page) {
            if (! array_key_exists($page['rootId'], $components)) {
                continue;
            }
            $stack = [[$page['rootId'], 1]];
            while ($stack !== []) {
                [$id, $depth] = array_pop($stack);
                if (isset($visited[$id])) {
                    $add("/components/{$id}", 'cycle', 'component graph contains a cycle or shared node');

                    continue;
                }
                $visited[$id] = true;
                if ($depth > Rules::MAX_DEPTH) {
                    $add("/components/{$id}", 'too_deep', 'nesting deeper than '.Rules::MAX_DEPTH.' levels');

                    continue;
                }
                $children = $components[$id]['children'];
                for ($i = count($children) - 1; $i >= 0; $i--) {
                    if (array_key_exists($children[$i], $components)) {
                        $stack[] = [$children[$i], $depth + 1];
                    }
                }
            }
        }
        foreach ($componentIds as $id) {
            if (! isset($visited[$id]) && $components[$id]['type'] !== 'Page') {
                $add("/components/{$id}", 'orphan', 'component is not reachable from any page');
            }
        }

        if (self::collectAssetRefs($components) !== array_values($doc['assets'])) {
            $add('/assets', 'assets_mismatch', 'assets must list exactly the referenced asset ids (sorted)');
        }

        return $issues;
    }

    /** @return list<string> sorted unique asset ids referenced as asset:<id> */
    public static function collectAssetRefs(array $components): array
    {
        $ids = [];
        foreach ($components as $node) {
            $def = Registry::component($node['type'] ?? '');
            if ($def === null) {
                continue;
            }
            foreach ($def['props'] as $key => $prop) {
                if ($prop['kind'] !== 'image') {
                    continue;
                }
                $value = $node['props'][$key] ?? null;
                if (is_string($value) && preg_match(Rules::ASSET_REF, $value) === 1) {
                    $ids[substr($value, 6)] = true;
                }
            }
        }
        $ids = array_map('strval', array_keys($ids));
        sort($ids, SORT_STRING);

        return $ids;
    }

    private static function validateText(mixed $value, array $def, bool $allowNewlines): ?string
    {
        if (! is_string($value)) {
            return 'must be a string';
        }
        if (isset($def['maxLength']) && mb_strlen($value, 'UTF-8') > $def['maxLength']) {
            return "must be at most {$def['maxLength']} characters";
        }
        if (! mb_check_encoding($value, 'UTF-8') || preg_match(Rules::FORBIDDEN_CONTROL, $value) === 1) {
            return 'contains control characters';
        }
        if (! $allowNewlines && preg_match(Rules::NEWLINE, $value) === 1) {
            return 'must be a single line';
        }

        return null;
    }

    public static function validatePropValue(array $def, mixed $value): ?string
    {
        switch ($def['kind']) {
            case 'text':
                return self::validateText($value, $def, false);
            case 'longtext':
                return self::validateText($value, $def, true);
            case 'url':
                return is_string($value) && Rules::isSafeHref($value) ? null : 'must be a safe link (/, #, http(s)://, mailto:, tel:)';
            case 'select':
                return is_string($value) && in_array($value, $def['options'] ?? [], true) ? null : 'is not an allowed option';
            case 'boolean':
                return is_bool($value) ? null : 'must be a boolean';
            case 'image':
                return is_string($value) && Rules::isSafeImageSource($value) ? null : 'must be empty, asset:<id> or an https:// URL';
            case 'slug':
                return is_string($value) && ($value === '' || preg_match(Rules::SLUG, $value) === 1) ? null : 'must be a lowercase slug';
            case 'videoId':
                return is_string($value) && ($value === '' || preg_match(Rules::VIDEO_ID, $value) === 1) ? null : 'must be a video ID';
            case 'links':
                if (! is_array($value) || ! array_is_list($value)) {
                    return 'must be a list';
                }
                if (isset($def['maxItems']) && count($value) > $def['maxItems']) {
                    return "must have at most {$def['maxItems']} items";
                }
                foreach ($value as $item) {
                    if (! is_array($item) || array_is_list($item) && $item !== []) {
                        return 'items must be objects';
                    }
                    $keys = array_map('strval', array_keys($item));
                    sort($keys);
                    if ($keys !== ['href', 'label']) {
                        return 'items must have exactly label and href';
                    }
                    $err = self::validateText($item['label'], ['maxLength' => 80], false);
                    if ($err !== null) {
                        return "item label {$err}";
                    }
                    if (! is_string($item['href']) || ! Rules::isSafeHref($item['href'])) {
                        return 'item href must be a safe link';
                    }
                }

                return null;
            case 'stringList':
                if (! is_array($value) || ! array_is_list($value)) {
                    return 'must be a list';
                }
                if (isset($def['maxItems']) && count($value) > $def['maxItems']) {
                    return "must have at most {$def['maxItems']} items";
                }
                foreach ($value as $item) {
                    $err = self::validateText($item, $def, false);
                    if ($err !== null) {
                        return "item {$err}";
                    }
                }

                return null;
            default:
                return 'has an unsupported kind';
        }
    }

    public static function validateStyleValue(string $property, mixed $value): ?string
    {
        $def = Registry::styleProperty($property);
        if ($def === null) {
            return 'is not an allowed style property';
        }
        $isNum = is_int($value) || is_float($value);
        switch ($def['kind']) {
            case 'enum':
                return is_string($value) && in_array($value, $def['values'], true) ? null : 'is not an allowed value';
            case 'integer':
                $isInt = is_int($value) || (is_float($value) && floor($value) === $value);

                return $isInt && $value >= $def['min'] && $value <= $def['max'] ? null : "must be an integer between {$def['min']} and {$def['max']}";
            case 'number':
                return $isNum && is_finite((float) $value) && $value >= $def['min'] && $value <= $def['max'] ? null : "must be a number between {$def['min']} and {$def['max']}";
            case 'length':
                return is_string($value) && preg_match(Rules::lengthPattern(), $value) === 1 ? null : 'must be a CSS length (e.g. 16px, 1.5rem, 50%)';
            case 'box':
                return is_string($value) && preg_match(Rules::boxPattern(), $value) === 1 ? null : 'must be 1–4 CSS lengths';
            case 'color':
                return is_string($value) && (preg_match(Rules::HEX_COLOR, $value) === 1 || preg_match(Rules::COLOR_TOKEN, $value) === 1 || $value === 'transparent')
                    ? null : 'must be a hex color, a color token ($primary…) or transparent';
            default:
                return 'has an unsupported kind';
        }
    }
}
