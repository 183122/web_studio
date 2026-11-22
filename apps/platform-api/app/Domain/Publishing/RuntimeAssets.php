<?php

namespace App\Domain\Publishing;

/** Locates the built runtime bundle (apps/runtime-web → public/runtime) via Vite's manifest. */
final class RuntimeAssets
{
    /** @return array{js: string, css: list<string>}|null */
    public static function entry(): ?array
    {
        $file = public_path('runtime/.vite/manifest.json');
        if (! is_file($file)) {
            return null;
        }
        $manifest = json_decode((string) file_get_contents($file), true);
        foreach ($manifest ?? [] as $chunk) {
            if (($chunk['isEntry'] ?? false) === true) {
                return ['js' => '/runtime/'.$chunk['file'], 'css' => array_map(fn ($c) => '/runtime/'.$c, $chunk['css'] ?? [])];
            }
        }

        return null;
    }
}
