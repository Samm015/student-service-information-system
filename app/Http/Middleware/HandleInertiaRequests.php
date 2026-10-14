<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => ['user' => $request->user()?->only('id', 'name', 'email', 'role')],
            // One-time messages set with ->with('success', ...) / ->with('error', ...).
            'flash' => [
                'success' => fn () => $request->hasSession() ? $request->session()->get('success') : null,
                'error'   => fn () => $request->hasSession() ? $request->session()->get('error') : null,
            ],
        ]);
    }
}
