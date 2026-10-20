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
        // Named, so its counter is its own: an unnamed throttle:N,M shares one with every other unnamed throttle of the same numbers.
        RateLimiter::for('lesson-room', fn (Request $request) => Limit::perMinute(20)->by((string) ($request->user()->id ?? $request->ip())));
        RateLimiter::for('video-webhooks', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));
        // Payment gateway events (23b): its own counter, so a burst of one webhook family never throttles the other.
        RateLimiter::for('payment-webhooks', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));
        // The operator status document (R193): per address, before the token is checked, so a guessing client is slowed whether or not it is right. 30 a minute is far above a person or a deploy check.
        RateLimiter::for('ops-status', fn (Request $request) => Limit::perMinute(30)->by((string) $request->ip()));
        // A tutor asking for a display name (R195): each request lands in an admin's queue, so 10 an hour per user.
        RateLimiter::for('display-name', fn (Request $request) => Limit::perHour(10)->by((string) $request->user()->id));
        // Per user, not per address: 30 messages a minute (R133), and 30 polls a minute for the badge counts (R135).
        RateLimiter::for('messages', fn (Request $request) => Limit::perMinute(30)->by((string) $request->user()->id));
        RateLimiter::for('unread-counts', fn (Request $request) => Limit::perMinute(30)->by((string) $request->user()->id));
        // One review per lesson is enforced by the unique index; this only bounds submit attempts (R136).
        RateLimiter::for('reviews', fn (Request $request) => Limit::perMinute(10)->by((string) $request->user()->id));
        // 5 per hour per user (CP7 8d, R137) — deliberately per hour, not per minute, to bound abuse-report spam.
        RateLimiter::for('abuse-reports', fn (Request $request) => Limit::perHour(5)->by((string) $request->user()->id));
        // One dispute per lesson is enforced by the unique index; this only bounds open attempts (CP8, R150) —
        // per hour, matching abuse-reports, since a dispute is a similarly rare, consequential action.
        RateLimiter::for('disputes', fn (Request $request) => Limit::perHour(5)->by((string) $request->user()->id));
        // Booking-shaped actions that create a lesson, a match request or a standing slot (R226): 10 a minute per user, one shared counter.
        RateLimiter::for('bookings', fn (Request $request) => Limit::perMinute(10)->by((string) $request->user()->id));
        // The tutor's onboarding uploads, the permit and the documents (R226): 10 a minute per user.
        RateLimiter::for('onboarding-uploads', fn (Request $request) => Limit::perMinute(10)->by((string) $request->user()->id));
        // The baseline for every other account write (R226): generous, per user (per address for a guest), and only for unsafe
        // methods, so it can sit on a whole route group without counting page views.
        RateLimiter::for('account-writes', fn (Request $request) => $request->isMethodSafe()
            ? Limit::none()
            : Limit::perMinute(60)->by((string) ($request->user()->id ?? $request->ip())));
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
