<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ImpersonationController extends Controller
{
    /**
     * Session key holding the id of the real user behind an impersonation.
     */
    public const ORIGINAL_USER_SESSION_KEY = 'impersonator_id';

    /**
     * Sign in as another user. Admins may become anyone; a manager may only become one of
     * the agents they created, which is enforced by the UserPolicy.
     */
    public function impersonate(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('impersonate', $user);

        $originalId = $request->session()->get(self::ORIGINAL_USER_SESSION_KEY, $request->user()->id);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Auth::login($user);

        // Survives the invalidate() above because it is written to the fresh session.
        $request->session()->put(self::ORIGINAL_USER_SESSION_KEY, $originalId);

        return redirect($user->isSuperAdmin() ? '/dashboard' : '/accounts');
    }

    /**
     * Return to the account that started the impersonation.
     */
    public function stop(Request $request): RedirectResponse
    {
        $originalId = $request->session()->get(self::ORIGINAL_USER_SESSION_KEY);

        abort_if($originalId === null, 403, 'You are not impersonating anyone.');

        $original = User::find($originalId);

        abort_if($original === null, 403, 'The original account no longer exists.');

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Auth::login($original);

        return redirect($original->isSuperAdmin() ? '/dashboard' : '/users');
    }
}
