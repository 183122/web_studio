<?php

namespace App\Domain\Data;

use App\Domain\Integrations\Webhooks\WebhookService;
use App\Models\AppAuditLog;
use App\Models\AppRecord;
use App\Models\Organization;
use App\Services\EntitlementService;
use App\Support\ApiException;
use App\Support\Like;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only write path for generated-app records: permission check → normalise/validate →
 * unique keys (atomic) → optimistic concurrency → app audit log → workflows (after commit).
 */
class RecordService
{
    public const ENV_PREVIEW = 'preview';

    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly WorkflowEngine $workflows,
        private readonly WebhookService $webhooks,
    ) {}

    public function create(DataContext $ctx, array $model, array $input, Actor $actor, string $source, string $env = self::ENV_PREVIEW, bool $runWorkflows = true): AppRecord
    {
        $ctx->assertAllowed($model, $actor, 'create');
        $values = RecordInput::validate($model, $input);

        $record = DB::transaction(function () use ($ctx, $model, $values, $actor, $source, $env) {
            $org = Organization::query()->whereKey($ctx->project->organization_id)->lockForUpdate()->firstOrFail();
            $this->entitlements->assertCanCreateRecord($org);
            $record = new AppRecord([
                'project_id' => $ctx->project->id,
                'environment' => $env,
                'model_id' => $model['id'],
                'version' => 1,
                'source' => $source,
                'created_by' => $actor->userId,
                'app_user_id' => $actor->appUserId,
            ]);
            $record->setValues($values);
            $record->save();
            $this->syncUniqueKeys($record, $model, $values);
            $this->audit($record, 'record.created', $actor, ['after' => $values]);

            return $record;
        });

        $this->webhooks->recordChanged($ctx->project->id, $model, $record, 'record.created');
        if ($runWorkflows) {
            $this->workflows->dispatch($ctx, $record, 'record.created');
        }

        return $record;
    }

    public function update(DataContext $ctx, array $model, AppRecord $record, array $input, ?int $expectedVersion, Actor $actor, string $source, bool $runWorkflows = true): AppRecord
    {
        $ctx->assertAllowed($model, $actor, 'update');

        $record = DB::transaction(function () use ($model, $record, $input, $expectedVersion, $actor) {
            /** @var AppRecord $fresh */
            $fresh = AppRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($expectedVersion !== null && $fresh->version !== $expectedVersion) {
                throw ApiException::conflict('record_conflict', 'ข้อมูลนี้ถูกแก้ไขไปแล้ว กรุณาโหลดใหม่', ['currentVersion' => $fresh->version]);
            }
            $before = $fresh->values();
            $values = RecordInput::validate($model, $input, $before);
            // Values of fields removed from the model are retained (safe evolution), not deleted.
            $merged = array_merge($before, $values);
            $changes = [];
            foreach ($values as $k => $v) {
                if (($before[$k] ?? null) !== $v) {
                    $changes[$k] = ['from' => $before[$k] ?? null, 'to' => $v];
                }
            }
            $fresh->setValues($merged);
            $fresh->version = $fresh->version + 1;
            $fresh->save();
            $this->syncUniqueKeys($fresh, $model, $values);
            $this->audit($fresh, 'record.updated', $actor, ['changes' => $changes]);

            return $fresh;
        });

        $this->webhooks->recordChanged($ctx->project->id, $model, $record, 'record.updated');
        if ($runWorkflows) {
            $this->workflows->dispatch($ctx, $record, 'record.updated');
        }

        return $record;
    }

    public function delete(DataContext $ctx, array $model, AppRecord $record, Actor $actor): void
    {
        $ctx->assertAllowed($model, $actor, 'delete');
        DB::transaction(function () use ($record, $actor) {
            DB::table('app_record_unique_keys')->where('record_id', $record->id)->delete();
            $record->delete();
            $this->audit($record, 'record.deleted', $actor, ['before' => $record->values()]);
        });
        $this->webhooks->recordChanged($ctx->project->id, $model, $record, 'record.deleted');
    }

    public function find(DataContext $ctx, array $model, string $id, string $env = self::ENV_PREVIEW): AppRecord
    {
        $record = $this->scope($ctx, $model, $env)->whereKey($id)->first();
        if ($record === null) {
            throw new ApiException(404, 'not_found', 'ไม่พบข้อมูล');
        }

        return $record;
    }

    /**
     * @param  array{search?:string, filters?:array<string,string>, sort?:string, dir?:string, perPage?:int}  $q
     */
    public function list(DataContext $ctx, array $model, Actor $actor, array $q, string $env = self::ENV_PREVIEW): LengthAwarePaginator
    {
        $ctx->assertAllowed($model, $actor, 'read');
        $fields = DataContext::fields($model);
        $query = $this->scope($ctx, $model, $env);

        if (! empty($q['search'])) {
            Like::contains($query, 'data', $q['search']);
        }
        foreach ($q['filters'] ?? [] as $fieldId => $raw) {
            if (! isset($fields[$fieldId]) || $raw === '' || $raw === null) {
                continue;
            }
            $value = RecordInput::normalize($fields[$fieldId], $raw);
            $query->where("data->{$fieldId}", $value);
        }

        $dir = ($q['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $sort = $q['sort'] ?? 'created_at';
        if (isset($fields[$sort])) {
            $this->orderByField($query, $sort, $fields[$sort]['type'], $dir);
        } elseif (in_array($sort, ['created_at', 'updated_at'], true)) {
            $query->orderBy($sort, $dir);
        }
        $query->orderBy('id', $dir);

        return $query->paginate(min(max((int) ($q['perPage'] ?? 20), 1), 100));
    }

    public function scope(DataContext $ctx, array $model, string $env = self::ENV_PREVIEW): Builder
    {
        return AppRecord::query()
            ->where('project_id', $ctx->project->id)
            ->where('environment', $env)
            ->where('model_id', $model['id']);
    }

    /** Compact representation; only the model's current fields are exposed. */
    public static function present(AppRecord $record, array $model, bool $byName = false): array
    {
        $values = $record->values();
        $data = [];
        foreach ($model['fields'] as $f) {
            $data[$byName ? $f['name'] : $f['id']] = $values[$f['id']] ?? null;
        }

        return [
            'id' => $record->id,
            'version' => $record->version,
            ($byName ? 'fields' : 'data') => (object) $data,
            'source' => $record->source,
            'createdAt' => $record->created_at?->toIso8601String(),
            'updatedAt' => $record->updated_at?->toIso8601String(),
        ];
    }

    private function orderByField(Builder $query, string $fieldId, string $type, string $dir): void
    {
        $column = $query->getQuery()->getGrammar()->wrap("data->{$fieldId}");
        if (in_array($type, ['number', 'integer'], true)) {
            $cast = $query->getConnection()->getDriverName() === 'mysql' ? 'DECIMAL(30,10)' : 'REAL';
            $query->orderByRaw("CAST({$column} AS {$cast}) {$dir}");
        } else {
            $query->orderByRaw("{$column} {$dir}");
        }
    }

    private function syncUniqueKeys(AppRecord $record, array $model, array $values): void
    {
        DB::table('app_record_unique_keys')->where('record_id', $record->id)->delete();
        foreach ($model['fields'] as $f) {
            $value = $values[$f['id']] ?? null;
            if (! $f['unique'] || $value === null) {
                continue;
            }
            try {
                DB::table('app_record_unique_keys')->insert([
                    'project_id' => $record->project_id,
                    'environment' => $record->environment,
                    'model_id' => $record->model_id,
                    'field_id' => $f['id'],
                    'value_hash' => hash('sha256', mb_strtolower(trim(is_scalar($value) ? (string) $value : json_encode($value)))),
                    'record_id' => $record->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw DataException::invalid([$f['id'] => 'already exists']);
            }
        }
    }

    private function audit(AppRecord $record, string $action, Actor $actor, array $changes): void
    {
        AppAuditLog::create([
            'project_id' => $record->project_id,
            'environment' => $record->environment,
            'model_id' => $record->model_id,
            'record_id' => $record->id,
            'action' => $action,
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
            'changes' => $changes,
            'ip_address' => $actor->ip,
        ]);
    }
}
