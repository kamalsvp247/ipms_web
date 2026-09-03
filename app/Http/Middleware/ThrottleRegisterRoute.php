<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

class ThrottleRegisterRoute
{
    /**
     * Fortify's register route carries no throttle middleware of its own, so this
     * applies the 'register' named limiter (see FortifyServiceProvider) the same
     * way `throttle:login` is applied to the login route.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (! $request->routeIs('register.store')) {
            return $next($request);
        }

        return app(ThrottleRequests::class)->handle($request, $next, 'register');
    }
}
