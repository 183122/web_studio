<?php

namespace App\Jobs;

use App\Domain\Publishing\PublishService;
use App\Models\Deployment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Runs a deployment on the isolated "build" queue. */
class PublishRelease implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $deploymentId)
    {
        $this->onQueue('build');
    }

    public function handle(PublishService $publisher): void
    {
        $d = Deployment::query()->find($this->deploymentId);
        if ($d !== null) {
            $publisher->run($d);
        }
    }

    public function failed(): void
    {
        Deployment::query()->whereKey($this->deploymentId)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'error_code' => 'worker_failed', 'error_message' => 'งานเผยแพร่หยุดก่อนเสร็จ เว็บไซต์เดิมยังใช้งานอยู่', 'finished_at' => now()]);
    }
}
