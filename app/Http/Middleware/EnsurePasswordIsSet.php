<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsSet
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->password) {
            return to_route('auth.set-password');
        }

        return $next($request);
    }
}
