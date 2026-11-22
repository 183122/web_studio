<?php

namespace App\Services;

use App\Domain\Schema\CommandApplier;
use App\Domain\Schema\CommandException;
use App\Domain\Schema\DefinitionValidator;
use App\Domain\Schema\DocumentCodec;
use App\Domain\Schema\SchemaException;
use App\Domain\Schema\StructuralValidator;
use App\Models\AppChangeEvent;
use App\Models\AppDefinition;
use App\Models\AppRevision;
use App\Models\Asset;
use App\Models\Project;
use App\Models\User;
use App\Support\ApiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only write path for application definitions.
 * Every mutation: lock head row → check revision (optimistic concurrency) → apply → validate →
 * verify tenant-owned assets → write head + append-only events (+ checkpoint when due).
 */
class AppDefinitionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Create the first revision of a brand-new project. Caller wraps in a transaction. */
    public function initialize(Project $project, array $doc, string $source, ?User $user, ?string $message = null): AppDefinition
    {
        $doc['projectId'] = $project->id;
        $this->assertDocument($project, $doc);
        $json = DocumentCodec::encode($doc);
        $hash = DocumentCodec::hash($json);

        $definition = AppDefinition::create([
            'project_id' => $project->id,
            'current_revision' => 1,
            'schema_version' => $doc['schemaVersion'],
            'document' => $json,
            'content_hash' => $hash,
            'size_bytes' => strlen($json),
            'updated_by' => $user?->id,
        ]);
        AppChangeEvent::create([
            'project_id' => $project->id, 'revision' => 1, 'seq' => 0,
            'command_type' => 'document.create',
            'payload' => json_encode(['source' => $source], DocumentCodec::JSON_FLAGS),
            'created_by' => $user?->id,
        ]);
        $this->checkpoint($project, 1, $json, $hash, $source, $message, $user, null);

        return $definition;
    }

    /** @return array{revision:int, document:array, contentHash:string, updatedAt:string|null} */
    public function head(Project $project): array
    {
        $def = AppDefinition::query()->where('project_id', $project->id)->firstOrFail();

        return [
            'revision' => $def->current_revision,
            'document' => DocumentCodec::decodeForResponse($def->document),
            'contentHash' => $def->content_hash,
            'updatedAt' => $def->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<mixed>  $commandObjects  commands decoded as objects (stdClass) for JSON Schema validation
     */
    public function applyCommands(Project $project, int $baseRevision, array $commandObjects, User $user, ?string $message = null): array
    {
        $max = (int) config('studio.schema.max_commands_per_request');
        if ($commandObjects === [] || count($commandObjects) > $max) {
            throw ApiException::unprocessable('invalid_batch', "a request must contain between 1 and {$max} commands");
        }
        $issues = [];
        foreach ($commandObjects as $i => $obj) {
            foreach (StructuralValidator::validateCommand($obj) as $issue) {
                $issue['path'] = "/commands/{$i}".($issue['path'] === '/' ? '' : $issue['path']);
                $issues[] = $issue;
            }
        }
        if ($issues !== []) {
            throw new SchemaException($issues, 'One or more commands are malformed.');
        }
        $commands = json_decode(json_encode($commandObjects, DocumentCodec::JSON_FLAGS), true);

        // Store the exact command JSON (object form preserves {} vs []).
        $events = [];
        foreach ($commandObjects as $i => $obj) {
            $events[] = ['type' => $commands[$i]['type'], 'json' => json_encode($obj, DocumentCodec::JSON_FLAGS)];
        }

        return DB::transaction(function () use ($project, $baseRevision, $commands, $events, $user, $message) {
            $def = $this->lockHead($project, $baseRevision);
            try {
                $doc = CommandApplier::applyAll(DocumentCodec::decode($def->document), $commands);
            } catch (CommandException $e) {
                throw ApiException::unprocessable('command_rejected', $e->getMessage(), [
                    'commandIndex' => $e->commandIndex,
                    'reason' => $e->errorCode,
                ]);
            }

            return $this->persist($project, $def, $doc, 'editor', $user, $message, $events, false, null);
        });
    }

    public function replace(Project $project, int $baseRevision, array $doc, User $user, ?string $message = null): array
    {
        return DB::transaction(function () use ($project, $baseRevision, $doc, $user, $message) {
            $def = $this->lockHead($project, $baseRevision);

            return $this->persist($project, $def, $doc, 'api', $user, $message, [['type' => 'document.replace', 'json' => json_encode(['message' => $message], DocumentCodec::JSON_FLAGS)]], true, null);
        });
    }

    /** Name the current head as an immutable checkpoint (no content change). */
    public function checkpointHead(Project $project, int $baseRevision, string $message, User $user): array
    {
        return DB::transaction(function () use ($project, $baseRevision, $message, $user) {
            $def = $this->lockHead($project, $baseRevision);
            $existing = AppRevision::query()->where('project_id', $project->id)->where('revision', $def->current_revision)->first();
            if ($existing !== null) {
                throw ApiException::conflict('checkpoint_exists', 'เวอร์ชันนี้ถูกบันทึกไว้แล้ว แก้ไขเพิ่มเติมก่อนบันทึกเวอร์ชันใหม่', ['revision' => $existing->revision]);
            }
            $this->checkpoint($project, $def->current_revision, $def->document, $def->content_hash, 'editor', mb_substr($message, 0, 200), $user, null);

            return ['revision' => $def->current_revision, 'checkpoint' => true];
        });
    }

    public function restore(Project $project, int $revision, int $baseRevision, User $user): array
    {
        $checkpoint = AppRevision::query()->where('project_id', $project->id)->where('revision', $revision)->first();
        if ($checkpoint === null) {
            throw new ApiException(404, 'revision_not_found', 'ไม่พบเวอร์ชันที่ต้องการกู้คืน');
        }
        $result = DB::transaction(function () use ($project, $checkpoint, $baseRevision, $user) {
            $def = $this->lockHead($project, $baseRevision);
            $doc = DocumentCodec::decode($checkpoint->document);

            return $this->persist(
                $project, $def, $doc, 'restore', $user, "กู้คืนจากเวอร์ชัน {$checkpoint->revision}",
                [['type' => 'document.restore', 'json' => json_encode(['fromRevision' => $checkpoint->revision], DocumentCodec::JSON_FLAGS)]],
                true, $checkpoint->revision,
            );
        });
        $this->audit->record('revision.restored', $project, ['from_revision' => $revision, 'new_revision' => $result['revision']]);

        return $result;
    }

    private function lockHead(Project $project, int $baseRevision): AppDefinition
    {
        $def = AppDefinition::query()->where('project_id', $project->id)->lockForUpdate()->firstOrFail();
        if ($def->current_revision !== $baseRevision) {
            throw ApiException::conflict('revision_conflict', 'เอกสารถูกแก้ไขจากที่อื่นแล้ว กรุณาโหลดเวอร์ชันล่าสุด', [
                'currentRevision' => $def->current_revision,
                'baseRevision' => $baseRevision,
            ]);
        }

        return $def;
    }

    /**
     * @param  list<array{type:string,json:string}>  $events
     */
    private function persist(Project $project, AppDefinition $def, array $doc, string $source, User $user, ?string $message, array $events, bool $forceCheckpoint, ?int $restoredFrom): array
    {
        $doc['projectId'] = $project->id;
        $this->assertDocument($project, $doc);

        $json = DocumentCodec::encode($doc);
        $hash = DocumentCodec::hash($json);
        $revision = $def->current_revision + 1;

        $def->forceFill([
            'current_revision' => $revision,
            'schema_version' => $doc['schemaVersion'],
            'document' => $json,
            'content_hash' => $hash,
            'size_bytes' => strlen($json),
            'updated_by' => $user->id,
        ])->save();
        $project->touch();

        foreach ($events as $seq => $event) {
            AppChangeEvent::create([
                'project_id' => $project->id,
                'revision' => $revision,
                'seq' => $seq,
                'command_type' => $event['type'],
                'payload' => $event['json'],
                'created_by' => $user->id,
            ]);
        }

        $checkpointed = false;
        if ($forceCheckpoint || ($message !== null && $message !== '') || $this->checkpointDue($project)) {
            $this->checkpoint($project, $revision, $json, $hash, $source, $message, $user, $restoredFrom);
            $checkpointed = true;
        }

        return [
            'revision' => $revision,
            'contentHash' => $hash,
            'checkpoint' => $checkpointed,
            'savedAt' => $def->updated_at?->toIso8601String(),
        ];
    }

    private function checkpointDue(Project $project): bool
    {
        $last = AppRevision::query()->where('project_id', $project->id)->max('created_at');
        if ($last === null) {
            return true;
        }

        return now()->diffInSeconds(Carbon::parse($last), true) >= (int) config('studio.schema.checkpoint_interval_seconds');
    }

    private function checkpoint(Project $project, int $revision, string $json, string $hash, string $source, ?string $message, ?User $user, ?int $restoredFrom): void
    {
        AppRevision::create([
            'project_id' => $project->id,
            'revision' => $revision,
            'document' => $json,
            'content_hash' => $hash,
            'size_bytes' => strlen($json),
            'source' => $source,
            'message' => $message !== null ? mb_substr($message, 0, 200) : null,
            'restored_from_revision' => $restoredFrom,
            'created_by' => $user?->id,
        ]);
    }

    /** Validate the document and make sure every referenced asset belongs to the project's workspace. */
    private function assertDocument(Project $project, array $doc): void
    {
        DefinitionValidator::assertValid($doc);
        $refs = $doc['assets'];
        if ($refs === []) {
            return;
        }
        $owned = Asset::query()
            ->where('workspace_id', $project->workspace_id)
            ->whereIn('id', $refs)
            ->pluck('id')
            ->all();
        $missing = array_values(array_diff($refs, $owned));
        if ($missing !== []) {
            throw new SchemaException(array_map(fn ($id) => [
                'path' => '/assets',
                'code' => 'asset_not_found',
                'message' => "asset {$id} does not exist in this workspace",
            ], $missing));
        }
    }
}
