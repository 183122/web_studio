<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/** Portable, escaped LIKE matching (MySQL / SQLite / PostgreSQL). */
final class Like
{
    public static function contains(Builder $query, string $column, string $term, string $boolean = 'and'): Builder
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);

        return $query->whereRaw($query->getQuery()->getGrammar()->wrap($column)." LIKE ? ESCAPE '!'", ['%'.$escaped.'%'], $boolean);
    }
}
