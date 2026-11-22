<?php

use App\Domain\Billing\BillingService;
use App\Domain\Integrations\Sheets\SheetSyncRunner;
use App\Domain\Publishing\DomainService;
use App\Models\Domain;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

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

// Platform admins are operators, never self-service: granted/revoked only from the server shell.
Artisan::command('studio:platform-admin {email} {--revoke}', function (string $email) {
    $user = User::query()->where('email', mb_strtolower(trim($email)))->first();
    if ($user === null) {
        $this->error('ไม่พบผู้ใช้');

        return 1;
    }
    $grant = ! $this->option('revoke');
    $user->forceFill(['is_platform_admin' => $grant])->save();
    app(AuditLogger::class)->record($grant ? 'platform.admin_granted' : 'platform.admin_revoked', $user, ['email' => $user->email]);
    $this->info(($grant ? 'granted' : 'revoked').": {$user->email}");

    return 0;
})->purpose('Grant or revoke platform-admin access for a user');

Artisan::command('studio:sheets:schedule', function (SheetSyncRunner $runner) {
    $this->info('queued '.$runner->dispatchDue().' sheet sync job(s)');
})->purpose('Queue due Google Sheets syncs');
Schedule::command('studio:sheets:schedule')->everyMinute()->withoutOverlapping();

Artisan::command('studio:billing:run', function (BillingService $billing) {
    $this->info(json_encode($billing->runDue()));
})->purpose('Renewals, trial/cancellation endings and dunning retries');
Schedule::command('studio:billing:run')->hourly()->withoutOverlapping();

Artisan::command('studio:audit:verify', function () {
    $broken = AuditLogger::verify();
    if ($broken === null) {
        $this->info('audit chain intact');

        return 0;
    }
    $this->error("audit chain broken at #{$broken['id']}: {$broken['reason']}");

    return 1;
})->purpose('Verify the tamper-evident audit log hash chain');
Schedule::command('studio:audit:verify')->dailyAt('03:17');

// PDPA retention schedule (periods in config/studio.php → retention).
Artisan::command('studio:retention:run', function () {
    $r = config('studio.retention');
    $cut = fn (int $days) => now()->subDays($days);
    $done = [
        'ai_requests' => DB::table('ai_requests')->where('created_at', '<', $cut($r['ai_requests_days']))->delete(),
        'webhook_deliveries' => DB::table('webhook_deliveries')->where('created_at', '<', $cut($r['webhook_deliveries_days']))->delete(),
        'integration_sync_jobs' => DB::table('integration_sync_jobs')->where('created_at', '<', $cut($r['sync_jobs_days']))->whereNotIn('status', ['queued', 'running', 'retrying'])->delete(),
        'app_audit_logs' => DB::table('app_audit_logs')->where('created_at', '<', $cut($r['app_audit_days']))->delete(),
        'billing_webhook_events' => DB::table('billing_webhook_events')->where('created_at', '<', $cut($r['billing_events_days']))->delete(),
        'preview_links' => DB::table('preview_links')->where('expires_at', '<', $cut(30))->delete(),
    ];
    // Organizations deleted by their owner: purge after the grace period (cascades to projects, data, files).
    $purged = 0;
    Organization::onlyTrashed()->where('deleted_at', '<', $cut($r['deleted_accounts_grace_days']))->get()->each(function ($org) use (&$purged) {
        Storage::disk('assets')->deleteDirectory("tenants/{$org->id}");
        app(AuditLogger::class)->record('privacy.organization_purged', null, ['organization_id' => $org->id]);
        $org->forceDelete();
        $purged++;
    });
    $done['organizations_purged'] = $purged;
    $this->info(json_encode($done));
})->purpose('Delete data past its retention period (PDPA)');
Schedule::command('studio:retention:run')->dailyAt('02:41');

// Heartbeat read by /api/health (detects a stopped scheduler).
Schedule::call(fn () => Cache::put('scheduler:heartbeat', time(), 600))->everyMinute()->name('scheduler-heartbeat');
