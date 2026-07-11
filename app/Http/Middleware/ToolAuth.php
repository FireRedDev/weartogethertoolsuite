<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Einfacher Zugangsschutz über ein gemeinsames Passwort (TOOL_PASSWORD in .env).
 * Leeres Passwort deaktiviert den Schutz (lokales Testen).
 */
class ToolAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $password = (string) config('ordersuite.password');
        if ($password === '' || $request->session()->get('tool_authenticated') === true) {
            return $next($request);
        }

        return redirect()->route('login');
    }
}
