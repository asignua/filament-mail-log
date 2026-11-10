<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Asignua\FilamentMailLog\MailLogPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Workbench\App\Filament\Resources\Orders\OrderResource;

class AdminPanelProvider extends PanelProvider
{
    public function boot(): void
    {
        // The log is closed until you open it (see the README); the demo opens it for the one demo user.
        // The test suite defines the gate itself and checks the closed default.
        if (!$this->app->runningUnitTests()) {
            Gate::define('viewMailLog', fn (): bool => true);
            Gate::define('resendMailLog', fn (): bool => true);
        }
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Acme Supply')
            ->resources([OrderResource::class])
            ->plugin(MailLogPlugin::make())
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                // Laravel 13 renamed VerifyCsrfToken to PreventRequestForgery; the string is the Laravel 12 fallback.
                class_exists(PreventRequestForgery::class) ? PreventRequestForgery::class : 'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class]);
    }
}
