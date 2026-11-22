<?php

namespace App\Jobs;

use App\Domain\Ai\AiService;
use App\Models\AiRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Runs one AI request on the isolated "ai" queue (long provider calls never block web workers). */
class RunAiRequest implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;          // the SDK already retries transient failures

    public int $timeout = 600;

    public function __construct(public string $requestId)
    {
        $this->onQueue('ai');
    }

    public function handle(AiService $ai): void
    {
        $request = AiRequest::query()->find($this->requestId);
        if ($request !== null) {
            $ai->execute($request);
        }
    }

    public function failed(): void
    {
        AiRequest::query()->whereKey($this->requestId)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'error_code' => 'ai_worker_failed', 'error_message' => 'งาน AI หยุดทำงานก่อนเสร็จ กรุณาลองใหม่', 'credits_charged' => 0]);
    }
}
