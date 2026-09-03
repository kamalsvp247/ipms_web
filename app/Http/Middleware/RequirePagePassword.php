<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePagePassword
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->get('page_password_unlocked') !== true) {
            return redirect()->route('page-password.show', ['redirect' => $request->fullUrl()]);
        }

        return $next($request);
    }
}
