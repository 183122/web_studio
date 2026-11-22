<?php

namespace App\Domain\Data;

use App\Domain\Schema\DocumentCodec;
use App\Domain\Schema\Upcaster;
use App\Models\AppDefinition;
use App\Models\Project;
use App\Models\Release;

/**
 * Read-only view of a project's data definitions. Phase 2 reads the current head (preview);
 * Phase 4 will read the immutable published release for the production environment.
 */
final class DataContext
{
    private function __construct(public readonly Project $project, public readonly array $doc) {}

    public static function forProject(Project $project): self
    {
        $def = AppDefinition::query()->where('project_id', $project->id)->firstOrFail();

        return new self($project, Upcaster::upcast(DocumentCodec::decode($def->document)));
    }

    /** Production runtime: definitions come from the immutable live release, never the draft. */
    public static function forRelease(Project $project, Release $release): self
    {
        return new self($project, Upcaster::upcast(DocumentCodec::decode($release->document)));
    }

    public function model(string $idOrName): array
    {
        foreach ($this->doc['dataModels'] as $m) {
            if ($m['id'] === $idOrName || $m['name'] === $idOrName) {
                return $m;
            }
        }
        throw DataException::modelNotFound();
    }

    /** @return array<string, array> fieldId => field */
    public static function fields(array $model): array
    {
        $out = [];
        foreach ($model['fields'] as $f) {
            $out[$f['id']] = $f;
        }

        return $out;
    }

    /** Allowed actions for an app role (explicit permissions, default deny). */
    public function allows(array $model, string $role, string $action): bool
    {
        foreach ($this->doc['permissions'] as $p) {
            if ($p['modelId'] === $model['id']) {
                return in_array($action, $p['rules'][$role] ?? [], true);
            }
        }

        return false;
    }

    public function assertAllowed(array $model, Actor $actor, string $action): void
    {
        if ($actor->type === 'user' || $actor->type === 'workflow') {
            return; // studio members and workflows act as the app owner
        }
        if (! $this->allows($model, $actor->role, $action)) {
            throw DataException::forbidden($action);
        }
    }
}
