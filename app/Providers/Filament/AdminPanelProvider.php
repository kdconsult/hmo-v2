<?php

namespace App\Providers\Filament;

use App\Filament\Resources\Partners\Widgets\PartnerOverview;
use App\Filament\Widgets\OssThresholdWidget;
use App\Http\Middleware\AdminPanelAuthenticate;
use App\Http\Middleware\EnsureActiveSubscription;
use App\Http\Middleware\SetSubdomainUrlDefault;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use LaraZeus\SpatieTranslatable\SpatieTranslatablePlugin;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->domain('{subdomain}.'.config('app.domain'))
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->plugin(
                SpatieTranslatablePlugin::make()
                    ->defaultLocales(['en'])
                    ->persist(),
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
                PartnerOverview::class,
                OssThresholdWidget::class,
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
                PreventAccessFromCentralDomains::class,
            ])
            ->middleware([
                InitializeTenancyBySubdomain::class,
                SetSubdomainUrlDefault::class,
            ], isPersistent: true)
            ->authMiddleware([
                AdminPanelAuthenticate::class,
                EnsureActiveSubscription::class,
            ])
            ->spa();
    }
}
