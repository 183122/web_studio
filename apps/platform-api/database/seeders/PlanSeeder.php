<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        // Prices are monthly, in satang, excluding 7% VAT.
        $plans = [
            ['code' => 'free', 'name' => 'Free', 'price_minor' => 0, 'trial_days' => 0, 'sort' => 1, 'entitlements' => [
                'max_projects' => 3, 'storage_bytes' => 100 * 1024 * 1024, 'max_workspaces' => 1, 'max_records' => 1000, 'ai_credits_monthly' => 100,
                'max_custom_domains' => 0, 'max_members' => 2, 'runtime_requests_monthly' => 10000, 'build_minutes_monthly' => 30,
            ]],
            ['code' => 'pro', 'name' => 'Pro', 'price_minor' => 49000, 'trial_days' => 14, 'sort' => 2, 'entitlements' => [
                'max_projects' => 25, 'storage_bytes' => 5 * 1024 * 1024 * 1024, 'max_workspaces' => 5, 'max_records' => 100000, 'ai_credits_monthly' => 3000,
                'max_custom_domains' => 5, 'max_members' => 10, 'runtime_requests_monthly' => 500000, 'build_minutes_monthly' => 600,
            ]],
            ['code' => 'business', 'name' => 'Business', 'price_minor' => 149000, 'trial_days' => 0, 'sort' => 3, 'entitlements' => [
                'max_projects' => 100, 'storage_bytes' => 50 * 1024 * 1024 * 1024, 'max_workspaces' => 20, 'max_records' => 1000000, 'ai_credits_monthly' => 15000,
                'max_custom_domains' => 25, 'max_members' => 50, 'runtime_requests_monthly' => 5000000, 'build_minutes_monthly' => 3000,
            ]],
        ];
        foreach ($plans as $plan) {
            Plan::query()->updateOrCreate(['code' => $plan['code']], $plan + ['currency' => 'THB', 'is_active' => true]);
        }
    }
}
