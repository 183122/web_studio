<?php

namespace App\Jobs;

use App\Models\WorkflowRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Plain-text workflow e-mail (no HTML from tenant content). Retried with backoff. */
class SendWorkflowEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public string $to, public string $subject, public string $body, public int $runId)
    {
        $this->onQueue('email');
    }

    public function handle(): void
    {
        Mail::raw($this->body, function (Message $m) {
            $m->to($this->to)->subject($this->subject);
        });
    }

    public function failed(Throwable $e): void
    {
        WorkflowRun::query()->whereKey($this->runId)->update(['status' => 'failed', 'error' => 'email: '.mb_substr($e->getMessage(), 0, 400)]);
    }
}
