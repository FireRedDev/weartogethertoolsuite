<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if ((string) config('ordersuite.password') === '') {
            return redirect()->route('home');
        }

        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        if (! hash_equals((string) config('ordersuite.password'), (string) $request->input('password'))) {
            return back()->withErrors(['password' => 'Falsches Passwort.']);
        }

        $request->session()->put('tool_authenticated', true);
        $request->session()->regenerate();

        return redirect()->route('home');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('tool_authenticated');
        $request->session()->invalidate();

        return redirect()->route('login');
    }
}
