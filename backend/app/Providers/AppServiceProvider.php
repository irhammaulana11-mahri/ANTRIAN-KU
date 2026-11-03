<?php

namespace App\Providers;

use App\Models\Branch;
use App\Models\Counter;
use App\Models\Employee;
use App\Models\PersonalAccessToken;
use App\Models\ServiceType;
use App\Models\Unit;
use App\Policies\BranchPolicy;
use App\Policies\CounterPolicy;
use App\Policies\EmployeePolicy;
use App\Policies\ServiceTypePolicy;
use App\Policies\UnitPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Gate::policy(Branch::class, BranchPolicy::class);
        Gate::policy(Unit::class, UnitPolicy::class);
        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(Counter::class, CounterPolicy::class);
        Gate::policy(ServiceType::class, ServiceTypePolicy::class);
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('auth.login', fn (Request $request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('queue.register', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('kiosk', fn (Request $request) => Limit::perMinute(30)->by('kiosk:'.($request->user()?->id ?: $request->ip())));
        RateLimiter::for('display.activate', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }
}
