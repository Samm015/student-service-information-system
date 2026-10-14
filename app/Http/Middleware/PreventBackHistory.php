<?php

namespace App\Http\Middleware;

use Closure;
use Inertia\Inertia;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tells the browser never to cache logged-in pages, so pressing Back after
 * logout re-asks the server (which redirects to login) instead of showing
 * a stale copy of someone's portal.
 */
class PreventBackHistory
{
    public function handle(Request $request, Closure $next): Response
    {
        // Inertia keeps a copy of each visited page in the browser history.
        // Encrypting it means that, after logout, the key is thrown away and
        // the old copies can no longer be read (see AuthController::logout).
        $inertia = Inertia::getFacadeRoot();
        if (method_exists($inertia, 'encryptHistory')) {
            $inertia->encryptHistory();
        }

        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
