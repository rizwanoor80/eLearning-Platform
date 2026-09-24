<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\User;
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
            ],
            // Read from the pages table when a page renders, so a new page or a
            // renamed one shows in the footer at once.
            'footerPages' => fn (): array => PublicPages::footerLinks(),
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
