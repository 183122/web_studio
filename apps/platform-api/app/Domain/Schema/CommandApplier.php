<?php

namespace App\Domain\Schema;

/**
 * Server-authoritative command reducer. Mirror of packages/app-schema/src/commands.ts
 * (inverse commands are a client concern and are not computed here).
 */
final class CommandApplier
{
    /** Apply a batch atomically; throws CommandException (with the failing index) on the first error. */
    public static function applyAll(array $doc, array $commands): array
    {
        foreach (array_values($commands) as $i => $cmd) {
            try {
                $doc = self::apply($doc, $cmd);
            } catch (CommandException $e) {
                throw $e->atIndex($i);
            }
        }

        return $doc;
    }

    public static function apply(array $doc, array $cmd): array
    {
        return match ($cmd['type'] ?? null) {
            'component.insert' => self::insert($doc, $cmd),
            'component.remove' => self::remove($doc, $cmd),
            'component.move' => self::move($doc, $cmd),
            'component.update' => self::update($doc, $cmd),
            'page.add' => self::pageAdd($doc, $cmd),
            'page.remove' => self::pageRemove($doc, $cmd),
            'page.update' => self::pageUpdate($doc, $cmd),
            'tokens.update' => self::tokensUpdate($doc, $cmd),
            'site.update' => self::siteUpdate($doc, $cmd),
            default => throw new CommandException('unknown_command', 'unsupported command "'.(is_string($cmd['type'] ?? null) ? $cmd['type'] : '?').'"'),
        };
    }

    private static function clampIndex(int|float $index, int $length): int
    {
        return max(0, min((int) $index, $length));
    }

    private static function requireNode(array $doc, string $id): array
    {
        if (! array_key_exists($id, $doc['components'])) {
            throw new CommandException('not_found', "component \"{$id}\" does not exist");
        }

        return $doc['components'][$id];
    }

    private static function requireContainer(array $doc, string $id): array
    {
        $node = self::requireNode($doc, $id);
        $def = Registry::component($node['type']);
        if ($def === null || ! $def['acceptsChildren']) {
            throw new CommandException('not_a_container', "{$node['type']} cannot contain children");
        }

        return $node;
    }

    private static function findParentId(array $doc, string $id): ?string
    {
        foreach ($doc['components'] as $node) {
            if (in_array($id, $node['children'], true)) {
                return $node['id'];
            }
        }

        return null;
    }

    /** @return list<array> pre-order subtree, root first */
    public static function collectSubtree(array $doc, string $id): array
    {
        $out = [];
        $seen = [];
        $walk = function (string $cid) use (&$walk, &$out, &$seen, $doc): void {
            if (isset($seen[$cid]) || ! array_key_exists($cid, $doc['components'])) {
                return;
            }
            $seen[$cid] = true;
            $node = $doc['components'][$cid];
            $out[] = $node;
            foreach ($node['children'] as $c) {
                $walk($c);
            }
        };
        $walk($id);

        return $out;
    }

    private static function withAssets(array $doc): array
    {
        $doc['assets'] = SemanticValidator::collectAssetRefs($doc['components']);

        return $doc;
    }

    private static function patch(array $target, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if ($value === null) {
                unset($target[$key]);
            } else {
                $target[$key] = $value;
            }
        }

        return $target;
    }

    private static function insert(array $doc, array $cmd): array
    {
        $parent = self::requireContainer($doc, $cmd['parentId']);
        $new = [];
        foreach ($cmd['nodes'] as $node) {
            if (array_key_exists($node['id'], $doc['components']) || isset($new[$node['id']])) {
                throw new CommandException('duplicate_id', "component \"{$node['id']}\" already exists");
            }
            if ($node['type'] === 'Page') {
                throw new CommandException('invalid_child', 'a Page cannot be inserted as a component');
            }
            $new[$node['id']] = true;
            $doc['components'][$node['id']] = $node;
        }
        $rootId = $cmd['nodes'][0]['id'];
        $children = $parent['children'];
        array_splice($children, self::clampIndex($cmd['index'], count($children)), 0, [$rootId]);
        $doc['components'][$parent['id']]['children'] = $children;

        return self::withAssets($doc);
    }

    private static function remove(array $doc, array $cmd): array
    {
        $node = self::requireNode($doc, $cmd['id']);
        if ($node['type'] === 'Page') {
            throw new CommandException('invalid_target', 'use page.remove to remove a page');
        }
        $parentId = self::findParentId($doc, $cmd['id']);
        if ($parentId === null) {
            throw new CommandException('not_found', "component \"{$cmd['id']}\" is not attached");
        }
        foreach (self::collectSubtree($doc, $cmd['id']) as $n) {
            unset($doc['components'][$n['id']]);
        }
        $doc['components'][$parentId]['children'] = array_values(array_filter(
            $doc['components'][$parentId]['children'],
            fn ($c) => $c !== $cmd['id'],
        ));

        return self::withAssets($doc);
    }

    private static function move(array $doc, array $cmd): array
    {
        $node = self::requireNode($doc, $cmd['id']);
        if ($node['type'] === 'Page') {
            throw new CommandException('invalid_target', 'a Page cannot be moved');
        }
        self::requireContainer($doc, $cmd['parentId']);
        $subtree = array_column(self::collectSubtree($doc, $cmd['id']), 'id');
        if (in_array($cmd['parentId'], $subtree, true)) {
            throw new CommandException('cycle', 'cannot move a component into itself or its descendants');
        }
        $oldParentId = self::findParentId($doc, $cmd['id']);
        if ($oldParentId === null) {
            throw new CommandException('not_found', "component \"{$cmd['id']}\" is not attached");
        }
        $doc['components'][$oldParentId]['children'] = array_values(array_filter(
            $doc['components'][$oldParentId]['children'],
            fn ($c) => $c !== $cmd['id'],
        ));
        $children = $doc['components'][$cmd['parentId']]['children'];
        array_splice($children, self::clampIndex($cmd['index'], count($children)), 0, [$cmd['id']]);
        $doc['components'][$cmd['parentId']]['children'] = $children;

        return $doc;
    }

    private static function update(array $doc, array $cmd): array
    {
        $node = self::requireNode($doc, $cmd['id']);
        if (array_key_exists('name', $cmd)) {
            if ($cmd['name'] === null || $cmd['name'] === '') {
                unset($node['name']);
            } else {
                $node['name'] = $cmd['name'];
            }
        }
        if (isset($cmd['props'])) {
            $node['props'] = self::patch($node['props'], $cmd['props']);
        }
        if (isset($cmd['styles'])) {
            foreach ($cmd['styles'] as $bp => $patch) {
                if (! is_array($patch)) {
                    continue;
                }
                $next = self::patch($node['styles'][$bp] ?? [], $patch);
                if ($next === []) {
                    unset($node['styles'][$bp]);
                } else {
                    $node['styles'][$bp] = $next;
                }
            }
        }
        $doc['components'][$cmd['id']] = $node;

        return self::withAssets($doc);
    }

    private static function pageIndex(array $doc, string $id): int
    {
        foreach ($doc['pages'] as $i => $p) {
            if ($p['id'] === $id) {
                return $i;
            }
        }

        return -1;
    }

    private static function pageAdd(array $doc, array $cmd): array
    {
        if (self::pageIndex($doc, $cmd['page']['id']) >= 0) {
            throw new CommandException('duplicate_id', "page \"{$cmd['page']['id']}\" already exists");
        }
        $root = $cmd['nodes'][0];
        if ($root['type'] !== 'Page' || $root['id'] !== $cmd['page']['rootId']) {
            throw new CommandException('invalid_root', 'first node must be the Page root');
        }
        $seen = [];
        foreach ($cmd['nodes'] as $node) {
            if (array_key_exists($node['id'], $doc['components']) || isset($seen[$node['id']])) {
                throw new CommandException('duplicate_id', "component \"{$node['id']}\" already exists");
            }
            $seen[$node['id']] = true;
            $doc['components'][$node['id']] = $node;
        }
        array_splice($doc['pages'], self::clampIndex($cmd['index'], count($doc['pages'])), 0, [$cmd['page']]);

        return self::withAssets($doc);
    }

    private static function pageRemove(array $doc, array $cmd): array
    {
        $index = self::pageIndex($doc, $cmd['id']);
        if ($index < 0) {
            throw new CommandException('not_found', "page \"{$cmd['id']}\" does not exist");
        }
        if ($doc['settings']['homePageId'] === $cmd['id']) {
            throw new CommandException('home_page', 'the home page cannot be removed');
        }
        if (($doc['settings']['notFoundPageId'] ?? null) === $cmd['id']) {
            throw new CommandException('not_found_page', 'unset the 404 page before removing it');
        }
        foreach (self::collectSubtree($doc, $doc['pages'][$index]['rootId']) as $n) {
            unset($doc['components'][$n['id']]);
        }
        array_splice($doc['pages'], $index, 1);

        return self::withAssets($doc);
    }

    private static function pageUpdate(array $doc, array $cmd): array
    {
        $index = self::pageIndex($doc, $cmd['id']);
        if ($index < 0) {
            throw new CommandException('not_found', "page \"{$cmd['id']}\" does not exist");
        }
        $changes = $cmd['changes'];
        if (array_key_exists('name', $changes)) {
            $doc['pages'][$index]['name'] = $changes['name'];
        }
        if (array_key_exists('path', $changes)) {
            $doc['pages'][$index]['path'] = $changes['path'];
        }
        if (array_key_exists('seo', $changes) && is_array($changes['seo'])) {
            $doc['pages'][$index]['seo'] = array_merge($doc['pages'][$index]['seo'], $changes['seo']);
        }

        return $doc;
    }

    private static function tokensUpdate(array $doc, array $cmd): array
    {
        $changes = $cmd['changes'];
        foreach (['colors', 'fonts'] as $group) {
            if (! isset($changes[$group]) || ! is_array($changes[$group])) {
                continue;
            }
            foreach ($changes[$group] as $k => $v) {
                if (! array_key_exists($k, $doc['designTokens'][$group])) {
                    throw new CommandException('unknown_token', "unknown ".rtrim($group, 's')." token \"{$k}\"");
                }
                $doc['designTokens'][$group][$k] = $v;
            }
        }
        if (array_key_exists('radius', $changes)) {
            $doc['designTokens']['radius'] = $changes['radius'];
        }

        return $doc;
    }

    private static function siteUpdate(array $doc, array $cmd): array
    {
        $changes = $cmd['changes'];
        if (array_key_exists('name', $changes)) {
            $doc['name'] = $changes['name'];
        }
        if (isset($changes['seo']) && is_array($changes['seo'])) {
            $doc['seo'] = array_merge($doc['seo'], $changes['seo']);
        }
        if (isset($changes['settings']) && is_array($changes['settings'])) {
            $doc['settings'] = array_merge($doc['settings'], $changes['settings']);
        }

        return $doc;
    }
}
