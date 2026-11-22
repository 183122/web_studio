<?php

namespace App\Jobs;

use App\Domain\Integrations\Sheets\SheetSyncRunner;
use App\Models\IntegrationSyncJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Isolated `google` queue. Retries are driven by the runner (backoff + dead-letter), not by exceptions. */
class RunSheetSync implements ShouldQueue
{
    use Queueable;

    public int $tries = SheetSyncRunner::MAX_ATTEMPTS + 2; // releases for lock contention included

    public int $timeout = 600;

    public function __construct(public string $jobId)
    {
        $this->onQueue('google');
    }

    public function handle(SheetSyncRunner $runner): void
    {
        $runner->execute($this->jobId, $this->attempts(), fn (int $delay) => $this->release($delay));
    }

    public function failed(Throwable $e): void
    {
        IntegrationSyncJob::query()->whereKey($this->jobId)->whereNotIn('status', ['succeeded', 'partial', 'failed'])
            ->update(['status' => 'dead', 'error' => 'งานล้มเหลวหลายครั้ง', 'finished_at' => now()]);
    }
}
