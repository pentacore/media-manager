<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $userRole = UserRole::from($role);

        abort_unless($request->user()?->role->isAtLeast($userRole), 403);

        return $next($request);
    }
}
