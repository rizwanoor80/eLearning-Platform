<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Enums\TutorProfileStatus;
use App\Models\TutorProfile;
use App\Models\User;
use App\Providers\PaymentGatewayServiceProvider;
use App\Support\Facades\Settings;
use App\Support\PublicPages;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => Settings::get('site_name', config('app.name')),
            'site' => [
                'tagline' => Settings::get('tagline'),
                'footer_text' => Settings::get('footer_text'),
            ],
            'auth' => [
                'user' => $request->user(),
                'home' => $this->homeRouteFor($request->user()),
                // R133: the Messages nav item shows for the two portals when the feature is on, so no `.vue` file branches on the role.
                'can_message' => EnsureFeatureEnabled::enabled('messaging') && $request->user()?->hasVerifiedEmail() === true && in_array($request->user()->role, [Role::AccountOwner, Role::Tutor], true),
                // R173(a): the tutor nav's "Complete your profile" entry, shown only while there is
                // still something to finish — never for an approved/pending/rejected/suspended
                // tutor. A tutor with no `tutor_profiles` row yet (registered, never opened
                // onboarding) still needs it: `profileFor()` only creates the row on first visit,
                // so "no row" must count as draft here, not as "nothing to do".
                'needs_onboarding' => $request->user()?->role === Role::Tutor && ! TutorProfile::query()
                    ->where('user_id', $request->user()->id)
                    ->whereNotIn('status', [TutorProfileStatus::Draft, TutorProfileStatus::ChangesRequested])
                    ->exists(),
            ],
            // Read from the pages table when a page renders, so a new page or a
            // renamed one shows in the footer at once.
            'footerPages' => fn (): array => PublicPages::footerLinks(),
            // True only where the fake gateway runs (R107): pages that book, save a card or show a
            // charge render the "Test mode — no real card is charged" banner from this.
            'paymentTestMode' => PaymentGatewayServiceProvider::fakeGatewayAllowed(),
            'features' => [
                'match_requests' => EnsureFeatureEnabled::enabled('match_requests'),
                'reviews' => EnsureFeatureEnabled::enabled('reviews'),
                'messaging' => EnsureFeatureEnabled::enabled('messaging'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * The single "Dashboard" nav link every layout renders is role-specific —
     * a tutor visiting the parent-only `dashboard` route gets a 403. Computed
     * once here so no `.vue` file branches on `auth.user.role` (CYCLE-LOG,
     * 3e dashboards slice).
     */
    private function homeRouteFor(?User $user): string
    {
        return match ($user?->role) {
            Role::AccountOwner => route('dashboard'),
            Role::Tutor => route('tutor.dashboard'),
            default => route('home'),
        };
    }
}
