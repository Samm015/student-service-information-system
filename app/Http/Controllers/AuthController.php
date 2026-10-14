<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $cred = $request->validate(['email' => 'required|email', 'password' => 'required']);
        $key  = Str::lower($cred['email']) . '|' . $request->ip();

    if (RateLimiter::tooManyAttempts($key, 5)) {
        return back()->withErrors(['email' => 'Too many attempts. Try again in ' . RateLimiter::availableIn($key) . ' seconds.']);
    }
    if (! Auth::attempt($cred)) {
        RateLimiter::hit($key, 60);
        return back()->withErrors(['email' => 'Invalid credentials.']);
    }
    RateLimiter::clear($key);

        // A deactivated account cannot log in (an administrator can switch it back on).
        if (! Auth::user()->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'This account has been deactivated. Please contact the administrator.']);
        }

        $request->session()->regenerate();
        return redirect('/' . Auth::user()->role);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Make the browser forget the encrypted copies of the pages it saw.
        $inertia = Inertia::getFacadeRoot();
        if (method_exists($inertia, 'clearHistory')) {
            $inertia->clearHistory();
        }

        // A full page load of /login (not a client-side page swap).
        return Inertia::location('/login');
    }
}
