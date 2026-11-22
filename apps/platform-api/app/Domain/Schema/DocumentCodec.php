<?php

namespace App\Domain\Schema;

use stdClass;

/**
 * Converts between canonical JSON text and PHP associative arrays.
 * PHP arrays cannot distinguish {} from [], so known object positions are restored before encoding.
 */
final class DocumentCodec
{
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public static function decode(string $json): array
    {
        return json_decode($json, true, 128, JSON_THROW_ON_ERROR);
    }

    /** Decode for API responses: keeps {} (objects) distinct from [] (lists). */
    public static function decodeForResponse(string $json): array
    {
        return self::restoreObjects(self::decode($json));
    }

    /** Canonical JSON text (sorted keys) of a document array. */
    public static function encode(array $doc): string
    {
        return json_encode(Canonical::sort(self::restoreObjects($doc)), self::JSON_FLAGS);
    }

    public static function hash(string $canonicalJson): string
    {
        return hash('sha256', $canonicalJson);
    }

    /** Object tree (stdClass) suitable for JSON Schema validation. */
    public static function toObjectTree(array $doc): mixed
    {
        return json_decode(json_encode(self::restoreObjects($doc), self::JSON_FLAGS), false, 128, JSON_THROW_ON_ERROR);
    }

    public static function restoreObjects(array $doc): array
    {
        $obj = static fn ($v) => (is_array($v) && $v === []) ? new stdClass : $v;

        if (isset($doc['components']) && is_array($doc['components'])) {
            foreach ($doc['components'] as $id => $node) {
                if (! is_array($node)) {
                    continue;
                }
                if (array_key_exists('props', $node)) {
                    $node['props'] = $obj($node['props']);
                }
                if (array_key_exists('styles', $node) && is_array($node['styles'])) {
                    foreach ($node['styles'] as $bp => $map) {
                        $node['styles'][$bp] = $obj($map);
                    }
                    $node['styles'] = $obj($node['styles']);
                }
                $doc['components'][$id] = $node;
            }
            $doc['components'] = $obj($doc['components']);
        }
        foreach (['seo', 'settings', 'designTokens'] as $key) {
            if (array_key_exists($key, $doc)) {
                $doc[$key] = $obj($doc[$key]);
            }
        }
        if (isset($doc['pages']) && is_array($doc['pages'])) {
            foreach ($doc['pages'] as $i => $page) {
                if (is_array($page) && array_key_exists('seo', $page)) {
                    $doc['pages'][$i]['seo'] = $obj($page['seo']);
                }
            }
        }

        return $doc;
    }
}
