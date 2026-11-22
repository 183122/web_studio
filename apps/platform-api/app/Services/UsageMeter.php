<?php

namespace App\Services;

use App\Jobs\SendBillingEmail;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Monthly usage counters (metering). Increments are single atomic UPDATEs, so concurrent requests
 * never lose counts. Crossing 80% / 100% of a plan limit e-mails the organization once per period.
 */
class UsageMeter
{
    /** metric => entitlement key and the unit conversion from counter value to the entitlement's unit */
    public const LIMITS = [
        'runtime_requests' => ['runtime_requests_monthly', 1],
        'build_seconds' => ['build_minutes_monthly', 60],
    ];

    /** Above this share of the limit a metered resource is blocked (soft limit = 100%: warnings only). */
    public const HARD_FACTOR = 1.5;

    public function __construct(private readonly EntitlementService $entitlements) {}

    public static function period(): string
    {
        return now()->format('Y-m');
    }

    public function add(string $orgId, string $metric, int $amount = 1): void
    {
        if ($amount <= 0) {
            return;
        }
        $period = self::period();
        $updated = DB::table('usage_counters')->where(['organization_id' => $orgId, 'metric' => $metric, 'period' => $period])
            ->update(['value' => DB::raw('value + '.(int) $amount), 'updated_at' => now()]);
        if ($updated === 0) {
            DB::table('usage_counters')->insertOrIgnore(['organization_id' => $orgId, 'metric' => $metric, 'period' => $period, 'value' => 0, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('usage_counters')->where(['organization_id' => $orgId, 'metric' => $metric, 'period' => $period])
                ->update(['value' => DB::raw('value + '.(int) $amount), 'updated_at' => now()]);
        }
        if (isset(self::LIMITS[$metric])) {
            $this->warnIfCrossed($orgId, $metric);
        }
    }

    public function value(string $orgId, string $metric, ?string $period = null): int
    {
        return (int) DB::table('usage_counters')->where(['organization_id' => $orgId, 'metric' => $metric, 'period' => $period ?? self::period()])->value('value');
    }

    /** Usage in the entitlement's unit and the limit. */
    public function status(Organization $org, string $metric): array
    {
        [$key, $div] = self::LIMITS[$metric];

        return ['used' => (int) ceil($this->value($org->id, $metric) / $div), 'limit' => $this->entitlements->limit($org, $key)];
    }

    /** Cached for a minute: cheap enough to call on every published-site request. */
    public function overHardLimit(string $orgId, string $metric): bool
    {
        return Cache::remember("usage_hard:{$orgId}:{$metric}", 60, function () use ($orgId, $metric) {
            $org = Organization::query()->with('plan')->find($orgId);
            if ($org === null) {
                return false;
            }
            $s = $this->status($org, $metric);

            return $s['limit'] > 0 && $s['used'] > $s['limit'] * self::HARD_FACTOR;
        });
    }

    private function warnIfCrossed(string $orgId, string $metric): void
    {
        $org = Organization::query()->with('plan')->find($orgId);
        if ($org === null) {
            return;
        }
        $s = $this->status($org, $metric);
        if ($s['limit'] <= 0) {
            return;
        }
        foreach ([100, 80] as $pct) {
            if ($s['used'] * 100 >= $s['limit'] * $pct) {
                if (Cache::add("usage_warn:{$orgId}:{$metric}:".self::period().":{$pct}", 1, now()->addDays(40))) {
                    $to = User::query()->whereKey($org->owner_id)->value('email');
                    $label = ['runtime_requests' => 'จำนวนคำขอเว็บไซต์', 'build_seconds' => 'เวลาการเผยแพร่'][$metric];
                    if ($to) {
                        SendBillingEmail::dispatch($to, "{$label} ใช้ไปแล้ว {$pct}% ของแพ็กเกจ", "{$label}เดือนนี้ใช้ไปแล้ว {$s['used']} จาก {$s['limit']} ".($pct >= 100 ? '— เกินกว่า '.((self::HARD_FACTOR - 1) * 100).'% ระบบจะหยุดให้บริการส่วนนั้นชั่วคราวจนถึงรอบใหม่หรืออัปเกรดแพ็กเกจ (ข้อมูลไม่ถูกลบ)' : '— พิจารณาอัปเกรดแพ็กเกจเพื่อไม่ให้สะดุด'))->afterCommit();
                    }
                }

                break;
            }
        }
    }
}
