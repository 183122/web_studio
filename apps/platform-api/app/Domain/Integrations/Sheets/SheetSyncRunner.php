<?php

namespace App\Domain\Integrations\Sheets;

use App\Domain\Integrations\Google\GoogleApiException;
use App\Jobs\RunSheetSync;
use App\Models\IntegrationSyncJob;
use App\Models\SheetSync;
use App\Models\User;
use App\Support\ApiException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Executes sync jobs: one at a time per sync (lock), retries Google rate limits/outages with
 * exponential backoff, and moves jobs that exhaust retries to a dead-letter state (`dead`) that a
 * user can retry from the job history.
 */
class SheetSyncRunner
{
    public const MAX_ATTEMPTS = 5;

    /** Seconds before attempt 2..5 */
    public const BACKOFF = [30, 120, 600, 1800];

    public function __construct(private readonly SheetSyncService $service) {}

    public function enqueue(SheetSync $sync, string $trigger, ?User $by = null, ?string $kind = null): IntegrationSyncJob
    {
        $job = IntegrationSyncJob::create([
            'project_id' => $sync->project_id, 'sync_id' => $sync->id, 'kind' => $kind ?? $sync->direction,
            'trigger' => $trigger, 'status' => 'queued', 'requested_by' => $by?->id,
        ]);
        RunSheetSync::dispatch($job->id);

        return $job;
    }

    /** @param callable(int): void $release re-queues the job after the given delay */
    public function execute(string $jobId, int $attempt, callable $release): void
    {
        $job = IntegrationSyncJob::query()->find($jobId);
        if ($job === null || in_array($job->status, ['succeeded', 'partial', 'failed', 'dead'], true)) {
            return;
        }
        $sync = SheetSync::query()->find($job->sync_id);
        if ($sync === null || $sync->status !== 'active') {
            $job->update(['status' => 'failed', 'error' => 'การซิงก์ถูกหยุดหรือการเชื่อมต่อ Google ถูกยกเลิก', 'finished_at' => now()]);

            return;
        }
        $lock = Cache::lock("sheet_sync:{$sync->id}", 900);
        if (! $lock->get()) {
            $release(30);

            return;
        }
        try {
            $job->update(['status' => 'running', 'attempts' => $attempt, 'started_at' => $job->started_at ?? now(), 'error' => null]);
            $stats = match ($job->kind) {
                'pull' => ['pull' => $this->service->pull($sync, false)],
                'push' => ['push' => $this->service->push($sync)],
                default => (function () use ($sync) {
                    $skip = [];
                    $pull = $this->service->pull($sync, false, $skip);

                    return ['pull' => $pull, 'push' => $this->service->push($sync, $skip)];
                })(),
            };
            $errors = $stats['pull']['errors'] ?? [];
            if (isset($stats['pull'])) {
                unset($stats['pull']['errors']);
            }
            $job->update([
                'status' => $errors === [] ? 'succeeded' : 'partial', 'stats' => $stats, 'errors' => $errors, 'finished_at' => now(),
            ]);
            $sync->forceFill(['last_synced_at' => now()])->save();
        } catch (GoogleApiException $e) {
            if ($e->retryable() && $attempt < self::MAX_ATTEMPTS) {
                $job->update(['status' => 'retrying', 'error' => $e->userMessage()]);
                $release(self::BACKOFF[$attempt - 1] ?? 1800);
            } else {
                $job->update(['status' => $e->retryable() ? 'dead' : 'failed', 'error' => $e->userMessage(), 'finished_at' => now()]);
            }
        } catch (ApiException $e) {
            $job->update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);
        } catch (Throwable $e) {
            report($e);
            if ($attempt < self::MAX_ATTEMPTS) {
                $job->update(['status' => 'retrying', 'error' => 'เกิดข้อผิดพลาดภายใน ระบบจะลองใหม่']);
                $release(self::BACKOFF[$attempt - 1] ?? 1800);
            } else {
                $job->update(['status' => 'dead', 'error' => 'เกิดข้อผิดพลาดภายในซ้ำหลายครั้ง', 'finished_at' => now()]);
            }
        } finally {
            $lock->release();
        }
    }

    /** Scheduler: due syncs with no job in flight. */
    public function dispatchDue(): int
    {
        $n = 0;
        SheetSync::query()->where('status', 'active')->where('interval_minutes', '>', 0)
            ->where(fn ($q) => $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', now()))
            ->limit(200)->get()
            ->each(function (SheetSync $sync) use (&$n) {
                $sync->forceFill(['next_run_at' => now()->addMinutes($sync->interval_minutes)])->save();
                if (IntegrationSyncJob::query()->where('sync_id', $sync->id)->whereIn('status', ['queued', 'running', 'retrying'])->exists()) {
                    return;
                }
                $this->enqueue($sync, 'schedule');
                $n++;
            });

        return $n;
    }
}
