<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCharacterIsSetup
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() &&
            ! $request->user()->has_setup_character &&
            ! $request->is('setup-character') &&
            ! $request->is('logout')) {
            return redirect()->route('character.setup');
        }

        return $next($request);
    }
}
