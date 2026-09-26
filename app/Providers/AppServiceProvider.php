<?php

namespace App\Providers;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Settings\SettingsService;
use App\Services\Video\VideoProviderManager;
use App\Services\Video\VideoRoomProvider;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SettingsService::class);

        // The active registry row decides the driver (invariant 16); with no active row this
        // throws NoActiveVideoProvider rather than falling back to anything hard-wired.
        $this->app->bind(VideoRoomProvider::class, fn ($app) => $app->make(VideoProviderManager::class)->active());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureGates();
        $this->configureRateLimiting();
    }

    /**
     * Define the role-based area gates.
     */
    protected function configureGates(): void
    {
        Gate::define('access-parent-area', fn (User $user): bool => $user->role === Role::AccountOwner);
        Gate::define('access-tutor-area', fn (User $user): bool => $user->role === Role::Tutor);
        Gate::define('access-admin-area', fn (User $user): bool => $user->role === Role::Admin && $user->status === UserStatus::Active);
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('video-webhooks', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
