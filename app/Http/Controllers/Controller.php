<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ServiceType;
use App\Models\User;
use App\Support\Abilities;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

abstract class Controller
{
    /**
     * The standard sentence for a page, deferred prop or write that needs a
     * service with no active connection.
     */
    protected function noActiveConnectionMessage(ServiceType $serviceType): string
    {
        return __('No active :service connection configured.', ['service' => $serviceType->label()]);
    }

    /**
     * The standard refusal when a page or write needs a service that has no
     * active connection: an error toast naming the service, then the
     * dashboard — or $redirectResponse (`back()` for an in-page write).
     */
    protected function noActiveConnectionRedirect(ServiceType $serviceType, ?RedirectResponse $redirectResponse = null): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $this->noActiveConnectionMessage($serviceType)]);

        return $redirectResponse ?? to_route('dashboard');
    }

    /**
     * The sentence for a service that answered but refused the request (a
     * bad API key, a wrong base URL). Only an admin can fix a connection, so
     * only an admin is sent to its settings; everyone else is told to ask one.
     */
    protected function upstreamRefusedMessage(ServiceType $serviceType, ?User $user): string
    {
        return $user?->can(Abilities::ADMIN) === true
            ? __(':service refused the request — check the connection settings.', ['service' => $serviceType->label()])
            : __(':service is unavailable — ask an admin to check the connection.', ['service' => $serviceType->label()]);
    }
}
