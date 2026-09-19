<?php

namespace App\Http\Middleware;

use App\Support\Facades\Settings;
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
            ],
            'features' => [
                'match_requests' => EnsureFeatureEnabled::enabled('match_requests'),
                'reviews' => EnsureFeatureEnabled::enabled('reviews'),
                'messaging' => EnsureFeatureEnabled::enabled('messaging'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
