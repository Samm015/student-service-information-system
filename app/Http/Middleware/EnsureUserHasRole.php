<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Backend role check. Use it on routes as  ->middleware('role:cashier')
 * or with several roles:  ->middleware('role:registration,cashier')
 *
 * Admins are always allowed (the README says admin "sees every portal").
 * Anyone else gets a 403, no matter what the frontend shows or hides.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if ($user->role === User::ROLE_ADMIN || in_array($user->role, $roles, true)) {
            return $next($request);
        }

        abort(403, 'You do not have access to this area.');
    }
}
