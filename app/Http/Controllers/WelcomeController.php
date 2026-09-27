<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\RegistrationGate;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class WelcomeController extends Controller
{
    /**
     * Render the landing page. canRegister is resolved per request because
     * RegistrationGate queries the users table, which must not run while the
     * route file loads (empty databases, route:cache, Octane workers).
     */
    public function __invoke(): Response
    {
        return Inertia::render('Welcome', [
            'canRegister' => Features::enabled(Features::registration()) && RegistrationGate::isOpen(),
        ]);
    }
}
