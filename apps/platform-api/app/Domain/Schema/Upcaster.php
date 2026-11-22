<?php

namespace App\Domain\Schema;

/** Mirror of packages/app-schema/src/upcast.ts. */
final class Upcaster
{
    public const CURRENT = 2;

    public static function upcast(array $doc): array
    {
        if (($doc['schemaVersion'] ?? null) === 1) {
            // v1 forced dataModels/permissions/workflows to be empty: only the version changes.
            $doc['schemaVersion'] = 2;
        }

        return $doc;
    }
}
