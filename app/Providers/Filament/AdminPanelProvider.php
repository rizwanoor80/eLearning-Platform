<?php

namespace App\Providers\Filament;

use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // R49 brand: maroon primary (600 = #64011D, 500 = #8A0A2A for dark mode); the deep-maroon sidebar and
            // admin radii live in the theme file. An explicit ramp, because Color::hex() lightens the seed to a pink.
            ->colors([
                'primary' => [
                    50 => 'rgb(255, 241, 240)',
                    100 => 'rgb(253, 226, 226)',
                    200 => 'rgb(249, 197, 200)',
                    300 => 'rgb(239, 154, 162)',
                    400 => 'rgb(217, 86, 107)',
                    500 => 'rgb(138, 10, 42)',
                    600 => 'rgb(100, 1, 29)',
                    700 => 'rgb(82, 1, 26)',
                    800 => 'rgb(62, 7, 21)',
                    900 => 'rgb(48, 6, 17)',
                    950 => 'rgb(36, 10, 18)',
                ],
            ])
            ->brandName('TrusTutor')
            ->brandLogo(fn () => view('filament.admin.brand'))
            ->brandLogoHeight('2rem')
            ->darkModeBrandLogo(fn () => view('filament.admin.brand-dark'))
            ->favicon(fn (): string => asset('brand/favicon-32.png'))
            ->viteTheme('resources/css/filament/admin/theme.css')
            // Schibsted Grotesk is self-hosted by the Vite fonts build (same files as the site): no runtime CDN.
            ->font('Schibsted Grotesk', provider: LocalFontProvider::class)
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): HtmlString => Vite::fonts('schibsted-grotesk'))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
