<?php

use App\Domain\Publishing\DomainService;
use App\Models\Domain;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Re-verifies custom domains: pending ones until they pass, active ones so a domain whose DNS now
// points elsewhere (or whose TXT record was removed) stops being served and stops getting certificates.
Artisan::command('studio:domains:check {--limit=200}', function (DomainService $domains) {
    $checked = 0;
    Domain::query()->where('type', 'custom')->whereNull('released_at')
        ->orderBy('last_checked_at')->limit((int) $this->option('limit'))->get()
        ->each(function (Domain $d) use ($domains, &$checked) {
            $before = $d->status;
            $domains->check($d);
            $checked++;
            if ($before !== $d->status) {
                $this->line("{$d->hostname}: {$before} → {$d->status}");
            }
        });
    $this->info("checked {$checked} domain(s)");
})->purpose('Re-run ownership, routing and certificate checks for custom domains');

Schedule::command('studio:domains:check')->everyTenMinutes()->withoutOverlapping();
