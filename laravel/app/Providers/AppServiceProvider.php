<?php

namespace App\Providers;

use App\Models\Application;
use App\Models\File;
use App\Models\Job;
use App\Policies\ApplicationPolicy;
use App\Policies\FilePolicy;
use App\Policies\JobPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if (str_starts_with((string) config('app.url'), 'https://')) URL::forceScheme('https');

        Gate::policy(Job::class, JobPolicy::class);
        Gate::policy(Application::class, ApplicationPolicy::class);
        Gate::policy(File::class, FilePolicy::class);

        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('auth', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
        // Visit tracking: generous per-IP limit because a whole college can share one IP.
        RateLimiter::for('track', fn (Request $r) => Limit::perMinute(600)->by($r->ip()));
        RateLimiter::for('uploads', fn (Request $r) => Limit::perMinute(20)->by($r->user()?->id ?: $r->ip()));

        // Password reset link points to the existing frontend
        ResetPassword::createUrlUsing(fn ($user, string $token) =>
            rtrim(env('FRONTEND_URL', config('app.url')), '/')
            .'/reset-password.html?token='.$token.'&email='.urlencode($user->email));
    }
}<?php

namespace App\Providers;

use App\Models\Application;
use App\Models\File;
use App\Models\Job;
use App\Policies\ApplicationPolicy;
use App\Policies\FilePolicy;
use App\Policies\JobPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if (str_starts_with((string) config('app.url'), 'https://')) URL::forceScheme('https');

        Gate::policy(Job::class, JobPolicy::class);
        Gate::policy(Application::class, ApplicationPolicy::class);
        Gate::policy(File::class, FilePolicy::class);

        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('auth', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
        // Visit tracking: generous per-IP limit because a whole college can share one IP.
        RateLimiter::for('track', fn (Request $r) => Limit::perMinute(600)->by($r->ip()));
        RateLimiter::for('uploads', fn (Request $r) => Limit::perMinute(20)->by($r->user()?->id ?: $r->ip()));

        // Password reset link points to the existing frontend
        ResetPassword::createUrlUsing(fn ($user, string $token) =>
            rtrim(env('FRONTEND_URL', config('app.url')), '/')
            .'/reset-password.html?token='.$token.'&email='.urlencode($user->email));
    }
}
