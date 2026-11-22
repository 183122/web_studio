<?php

namespace App\Domain\Data;

use App\Jobs\SendWorkflowEmail;
use App\Models\AppRecord;
use App\Models\WorkflowRun;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * Executes declarative workflows (trigger → conditions → allowlisted actions).
 * - Idempotent: one run per (workflow, record, event, record version).
 * - No recursion: field updates made by workflows do not trigger workflows.
 * - Side effects (e-mail) are queued with retries; dry runs never write.
 */
class WorkflowEngine
{
    public function dispatch(DataContext $ctx, AppRecord $record, string $event): void
    {
        $model = $ctx->model($record->model_id);
        foreach ($ctx->doc['workflows'] as $workflow) {
            if (! $workflow['enabled'] || $workflow['trigger']['modelId'] !== $record->model_id || $workflow['trigger']['event'] !== $event) {
                continue;
            }
            $this->run($ctx, $model, $workflow, $record, $event);
        }
    }

    public function run(DataContext $ctx, array $model, array $workflow, AppRecord $record, string $event): ?WorkflowRun
    {
        try {
            $run = WorkflowRun::create([
                'project_id' => $record->project_id,
                'environment' => $record->environment,
                'workflow_id' => $workflow['id'],
                'record_id' => $record->id,
                'event' => $event,
                'event_key' => "{$event}:v{$record->version}",
                'status' => 'running',
                'steps' => [],
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // already processed (idempotency)
        }

        $plan = $this->plan($model, $workflow, $record->values());
        if (! $plan['matched']) {
            $run->update(['status' => 'skipped', 'steps' => $plan['steps']]);

            return $run;
        }

        $steps = $plan['steps'];
        try {
            $patch = [];
            foreach ($plan['actions'] as $i => $action) {
                if ($action['type'] === 'record.setField') {
                    $patch[$action['fieldId']] = $action['value'];
                    $steps[] = ['action' => $i, 'type' => 'record.setField', 'status' => 'done', 'fieldId' => $action['fieldId']];
                } else {
                    // Queued after commit so a rolled-back write never sends mail.
                    SendWorkflowEmail::dispatch($action['to'], $action['subject'], $action['body'], $run->id)->afterCommit();
                    $steps[] = ['action' => $i, 'type' => 'email.send', 'status' => 'queued', 'to' => self::maskEmail($action['to'])];
                }
            }
            if ($patch !== []) {
                app(RecordService::class)->update($ctx, $model, $record, $patch, null, Actor::workflow($workflow['id']), 'workflow', runWorkflows: false);
            }
            $run->update(['status' => 'succeeded', 'steps' => $steps]);
        } catch (Throwable $e) {
            report($e);
            $run->update(['status' => 'failed', 'steps' => $steps, 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }

        return $run;
    }

    /**
     * Pure evaluation (used by dry runs): conditions and resolved actions, no side effects.
     *
     * @return array{matched: bool, steps: list<array>, actions: list<array>}
     */
    public function plan(array $model, array $workflow, array $values): array
    {
        $fields = DataContext::fields($model);
        $steps = [];
        $matched = true;
        foreach ($workflow['conditions'] as $i => $c) {
            $ok = self::evaluate($c, $values[$c['fieldId']] ?? null);
            $steps[] = ['condition' => $i, 'fieldId' => $c['fieldId'], 'op' => $c['op'], 'result' => $ok];
            $matched = $matched && $ok;
        }
        $actions = [];
        if ($matched) {
            $byName = [];
            foreach ($fields as $id => $f) {
                $byName[$f['name']] = $values[$id] ?? null;
            }
            foreach ($workflow['actions'] as $a) {
                if ($a['type'] === 'email.send') {
                    $to = str_starts_with($a['to'], 'field:') ? ($values[substr($a['to'], 6)] ?? null) : $a['to'];
                    if (! is_string($to) || preg_match(FieldValue::EMAIL, $to) !== 1) {
                        $steps[] = ['type' => 'email.send', 'status' => 'skipped', 'reason' => 'no valid recipient'];

                        continue;
                    }
                    $actions[] = [
                        'type' => 'email.send',
                        'to' => $to,
                        'subject' => self::render($a['subject'], $byName, true),
                        'body' => self::render($a['body'], $byName, false),
                    ];
                } else {
                    $actions[] = $a;
                }
            }
        }

        return ['matched' => $matched, 'steps' => $steps, 'actions' => $actions];
    }

    public static function evaluate(array $c, mixed $value): bool
    {
        $empty = $value === null || $value === '';
        $target = $c['value'] ?? null;

        return match ($c['op']) {
            'empty' => $empty,
            'not_empty' => ! $empty,
            'eq' => ! $empty && (string) (is_bool($value) ? ($value ? 'true' : 'false') : $value) === (string) (is_bool($target) ? ($target ? 'true' : 'false') : $target),
            'neq' => $empty || (string) (is_bool($value) ? ($value ? 'true' : 'false') : $value) !== (string) (is_bool($target) ? ($target ? 'true' : 'false') : $target),
            'contains' => is_string($value) && is_scalar($target) && mb_stripos($value, (string) $target) !== false,
            'gt' => (is_int($value) || is_float($value)) && $value > $target,
            'lt' => (is_int($value) || is_float($value)) && $value < $target,
            default => false,
        };
    }

    /** Plain-text templating: {{field_name}} → value. Subjects are kept single-line. */
    public static function render(string $template, array $byName, bool $singleLine): string
    {
        return (string) preg_replace_callback(FieldValue::PLACEHOLDER.'u', function ($m) use ($byName, $singleLine) {
            $v = $byName[$m[1]] ?? '';
            $s = is_bool($v) ? ($v ? 'ใช่' : 'ไม่ใช่') : (string) $v;

            return $singleLine ? str_replace(["\r", "\n"], ' ', $s) : $s;
        }, $template);
    }

    private static function maskEmail(string $email): string
    {
        [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($user, 0, 2).'***@'.$domain;
    }
}
