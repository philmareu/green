<?php

namespace App\Providers\Filament;

use App\Filament\Pages\NotificationPreferences;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('')
            ->brandName('Green')
            ->login()
            ->profile()
            ->databaseNotifications()
            ->userMenuItems([
                Action::make('notifications')
                    ->label('Notifications')
                    ->url(fn (): string => NotificationPreferences::getUrl())
                    ->icon(Heroicon::OutlinedBellAlert),
            ])
            // Bank data sits behind this login: production makes everyone set up an
            // authenticator app on their next sign-in.
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
            ], isRequired: fn (): bool => app()->isProduction())
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth(Width::Full)
            ->colors([
                'primary' => Color::Emerald,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
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
            ])
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn (): string => $this->loginLinks().$this->privacyLink());
    }

    protected function privacyLink(): string
    {
        return Blade::render(<<<'BLADE'
            <p style="text-align: center; font-size: 0.875rem;">
                <x-filament::link :href="route('privacy')" color="gray">Privacy policy</x-filament::link>
            </p>
            BLADE);
    }

    /**
     * One-click "Log in as" buttons for local development, when
     * LOGIN_LINK_ENABLED is on (spatie/laravel-login-link, a dev dependency).
     *
     * Rendered from a string rather than a view file: `php artisan view:cache`
     * compiles every view file on deploy, where the package isn't installed.
     */
    protected function loginLinks(): string
    {
        if (! app()->environment(config('login-link.allowed_environments', []))) {
            return '';
        }

        return Blade::render(<<<'BLADE'
            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                @foreach ($users as $user)
                    <x-login-link :email="$user->email" :label="'Log in as '.$user->email" />
                @endforeach
            </div>
            BLADE, ['users' => User::query()->orderBy('id')->get()]);
    }
}
