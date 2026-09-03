<?php

namespace App\Http\Middleware;

use App\Http\Controllers\ImpersonationController;
use App\Models\Notice;
use App\Models\Setting;
use App\Support\AccountLockWindow;
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
        $settings = Setting::instance();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'permissions' => $request->user() ? [
                    'settings.read' => $request->user()->can('settings.read'),
                    'settings.write' => $request->user()->can('settings.write'),
                    'captcha.read' => $request->user()->can('captcha.read'),
                    'captcha.write' => $request->user()->can('captcha.write'),
                    'accounts.read' => $request->user()->can('accounts.read'),
                    'accounts.write' => $request->user()->can('accounts.write'),
                    'proxies.read' => $request->user()->can('proxies.read'),
                    'proxies.write' => $request->user()->can('proxies.write'),
                    'bot.manage' => $request->user()->can('bot.manage'),
                    'accounts.assign' => $request->user()->can('accounts.assign'),
                    'users.manage' => $request->user()->can('users.manage'),
                    'logs.read' => $request->user()->can('logs.read'),
                    'api-tester.access' => $request->user()->can('api-tester.access'),
                    'notice.write' => $request->user()->can('notice.write'),
                ] : [],
                'impersonating' => $request->session()->has(ImpersonationController::ORIGINAL_USER_SESSION_KEY),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'windowStartTime' => $settings->window_start_time,
            'windowEndTime' => $settings->window_end_time,
            'serverTimestampMs' => (int) (microtime(true) * 1000),
            // The daily agent lock window. Shared as the raw times rather than a boolean so the page
            // re-evaluates it against its own ticking Dhaka clock — a session that was already open
            // when the window opened locks itself without a reload.
            'accountLock' => AccountLockWindow::payload($settings),
            // Every enabled notice, in display order — empty when there is nothing to
            // say, so the header marquee is a plain v-if.
            'notices' => Notice::activeTexts(),
        ];
    }
}
