<?php

namespace Database\Seeders;

use App\Models\Template;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProjectService;
use App\Services\TenantProvisioner;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([PlanSeeder::class, TemplateSeeder::class]);

        if (app()->isProduction()) {
            return;
        }

        // Local demo account (never seeded in production).
        $user = User::query()->firstOrCreate(['email' => 'demo@example.com'], [
            'name' => 'ผู้ใช้ตัวอย่าง',
            'password' => 'DemoPassword123',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        if ($user->organizations()->doesntExist()) {
            app(TenantProvisioner::class)->provision($user, 'สถาบันฝึกอบรมตัวอย่าง');
            $workspace = Workspace::query()->whereHas('members', fn ($q) => $q->where('users.id', $user->id))->firstOrFail();
            $template = Template::query()->where('slug', 'training-registration')->with('currentVersion')->first();
            app(ProjectService::class)->create($workspace, $user, 'ระบบลงทะเบียนอบรม', 'โปรเจกต์ตัวอย่าง (MVP showcase)', $template);
        }
    }
}
