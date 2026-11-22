<?php

namespace App\Domain\Schema;

use RuntimeException;

/** Loads the shared component registry (packages/component-registry/registry.json). */
final class Registry
{
    private static ?array $data = null;

    public static function data(): array
    {
        if (self::$data === null) {
            $path = config('studio.packages_path').'/component-registry/registry.json';
            if (! is_file($path)) {
                throw new RuntimeException("Component registry not found at {$path}");
            }
            self::$data = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        }

        return self::$data;
    }

    public static function component(string $type): ?array
    {
        return self::data()['components'][$type] ?? null;
    }

    public static function styleProperty(string $name): ?array
    {
        return self::data()['styleProperties'][$name] ?? null;
    }
}
