<?php

namespace App\Providers;

use App\Services\Scanning\ClamAvScanner;
use App\Services\Scanning\MalwareScanner;
use App\Services\Scanning\NullScanner;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MalwareScanner::class, function () {
            $cfg = config('studio.assets.clamav');

            return $cfg['host']
                ? new ClamAvScanner($cfg['host'], (int) $cfg['port'], (int) $cfg['timeout'])
                : new NullScanner;
        });
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // E-mail links point at the studio origin (same-origin API proxy), signed with relative URLs.
        VerifyEmail::createUrlUsing(function ($notifiable) {
            $relative = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ], absolute: false);

            return config('studio.frontend_url').$relative;
        });
        ResetPassword::createUrlUsing(fn ($user, string $token) => config('studio.frontend_url')
            .'/reset-password?token='.urlencode($token).'&email='.urlencode($user->getEmailForPasswordReset()));

        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(240)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('commands', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('uploads', fn (Request $r) => Limit::perMinute(30)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(5)->by(mb_strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perMinute(30)->by($r->ip()),
        ]);
        RateLimiter::for('register', fn (Request $r) => Limit::perHour(10)->by($r->ip()));
        RateLimiter::for('password', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
    }
}
