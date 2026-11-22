<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Prometheus text metrics for operators. Disabled unless METRICS_TOKEN is set; bearer-token protected. */
class MetricsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $token = (string) config('studio.metrics_token');
        abort_if($token === '' || ! hash_equals($token, (string) $request->bearerToken()), 404);

        $g = [
            'studio_users_total' => DB::table('users')->count(),
            'studio_organizations_total' => DB::table('organizations')->whereNull('deleted_at')->count(),
            'studio_organizations_suspended' => DB::table('organizations')->where('status', 'suspended')->count(),
            'studio_projects_total' => DB::table('projects')->count(),
            'studio_subscriptions_active' => DB::table('subscriptions')->where('status', 'active')->count(),
            'studio_subscriptions_past_due' => DB::table('subscriptions')->where('status', 'past_due')->count(),
            'studio_queue_failed_jobs' => DB::table('failed_jobs')->count(),
            'studio_deployments_failed_24h' => DB::table('deployments')->where('status', 'failed')->where('created_at', '>', now()->subDay())->count(),
            'studio_sheet_sync_jobs_dead' => DB::table('integration_sync_jobs')->where('status', 'dead')->count(),
            'studio_webhook_deliveries_dead_24h' => DB::table('webhook_deliveries')->where('status', 'dead')->where('updated_at', '>', now()->subDay())->count(),
            'studio_billing_webhooks_rejected_24h' => DB::table('billing_webhook_events')->where('status', 'rejected')->where('created_at', '>', now()->subDay())->count(),
            'studio_abuse_reports_open' => DB::table('abuse_reports')->where('status', 'open')->count(),
            'studio_audit_chain_intact' => Cache::remember('metrics:audit_chain', 600, fn () => AuditLogger::verify() === null ? 1 : 0),
        ];
        $out = '';
        foreach ($g as $name => $value) {
            $out .= "# TYPE {$name} gauge\n{$name} {$value}\n";
        }

        return response($out, 200, ['Content-Type' => 'text/plain; version=0.0.4']);
    }
}
