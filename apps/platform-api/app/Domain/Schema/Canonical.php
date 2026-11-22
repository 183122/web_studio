<?php

namespace App\Domain\Schema;

/**
 * Canonical JSON for hashing/equality: object keys sorted recursively, arrays keep order.
 * Documents are handled as associative arrays; DocumentCodec restores {} vs [] semantics.
 */
final class Canonical
{
    public static function sort(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
            ksort($value, SORT_STRING);

            return (object) array_map([self::class, 'sort'], $value);
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map([self::class, 'sort'], $value);
            }
            ksort($value, SORT_STRING);

            return array_map([self::class, 'sort'], $value);
        }

        return $value;
    }
}
