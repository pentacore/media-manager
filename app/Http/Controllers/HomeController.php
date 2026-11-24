<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * The app has no landing page: `/` sends a signed-in user to the
     * dashboard and a guest to the login page (which offers registration
     * while it is open).
     */
    public function __invoke(Request $request): RedirectResponse
    {
        return to_route($request->user() ? 'dashboard' : 'login');
    }
}
