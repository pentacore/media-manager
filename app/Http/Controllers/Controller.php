<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ServiceType;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

abstract class Controller
{
    /**
     * The standard refusal when a page or write needs a service that has no
     * active connection: an error toast naming the service, then the
     * dashboard — or $redirectResponse (`back()` for an in-page write).
     */
    protected function noActiveConnectionRedirect(ServiceType $serviceType, ?RedirectResponse $redirectResponse = null): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => __('No active :service connection configured.', ['service' => $serviceType->label()])]);

        return $redirectResponse ?? to_route('dashboard');
    }
}
