<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            config(['app.debug' => true]);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Gate::define('settings.read', fn ($user) => $user->hasPermission('settings.read'));
        Gate::define('settings.write', fn ($user) => $user->hasPermission('settings.write'));
        Gate::define('captcha.read', fn ($user) => $user->hasPermission('captcha.read'));
        Gate::define('captcha.write', fn ($user) => $user->hasPermission('captcha.write'));
        Gate::define('accounts.read', fn ($user) => $user->hasPermission('accounts.read'));
        Gate::define('accounts.write', fn ($user) => $user->hasPermission('accounts.write'));
        Gate::define('proxies.read', fn ($user) => $user->hasPermission('proxies.read'));
        Gate::define('proxies.write', fn ($user) => $user->hasPermission('proxies.write'));
        Gate::define('bot.manage', fn ($user) => $user->isSuperAdmin());
        Gate::define('accounts.assign', fn ($user) => $user->hasPermission('accounts.assign'));
        Gate::define('users.manage', fn ($user) => $user->hasPermission('users.manage'));
        Gate::define('logs.read', fn ($user) => $user->hasPermission('logs.read'));
        // The request tester talks to IVAC directly, so it stays with the admin and manager
        // tiers — sub managers and agents never see it.
        Gate::define('api-tester.access', fn ($user) => ! $user->isAgent() && ! $user->isSubManager());

        // The header notice is authored by the operator, not delegated: super admins and
        // managers write it, sub managers and agents only read it off the shared Inertia prop.
        Gate::define('notice.write', fn ($user) => $user->isSuperAdmin() || $user->isManager());
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
            : null
        );
    }
}
