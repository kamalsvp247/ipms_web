<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PagePasswordController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('PagePassword/Index', [
            'redirectTo' => $request->query('redirect', route('dashboard')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['password' => 'required|string']);

        $pagePassword = config('app.page_password');

        if (empty($pagePassword) || $request->input('password') !== $pagePassword) {
            return back()->withErrors(['password' => 'Incorrect password.']);
        }

        $request->session()->put('page_password_unlocked', true);

        $redirectTo = $request->input('redirect_to', route('dashboard'));

        return redirect($redirectTo);
    }
}
