<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

class SendBillingEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [30, 120, 600];

    public function __construct(public string $to, public string $subject, public string $body)
    {
        $this->onQueue('email');
    }

    public function handle(): void
    {
        Mail::raw($this->body, fn (Message $m) => $m->to($this->to)->subject('[AI Studio Cloud] '.$this->subject));
    }
}
