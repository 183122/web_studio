<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['code' => 'free', 'name' => 'Free', 'price_minor' => 0, 'entitlements' => [
                'max_projects' => 3, 'storage_bytes' => 100 * 1024 * 1024, 'max_workspaces' => 1, 'max_records' => 1000, 'ai_credits_monthly' => 100,
            ]],
            ['code' => 'pro', 'name' => 'Pro', 'price_minor' => 49000, 'entitlements' => [
                'max_projects' => 25, 'storage_bytes' => 5 * 1024 * 1024 * 1024, 'max_workspaces' => 5, 'max_records' => 100000, 'ai_credits_monthly' => 3000,
            ]],
        ];
        foreach ($plans as $plan) {
            Plan::query()->updateOrCreate(['code' => $plan['code']], $plan + ['currency' => 'THB', 'is_active' => true]);
        }
    }
}
