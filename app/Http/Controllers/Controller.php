<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Arr\LibraryCacheSync;
use App\Support\UpstreamRefusal;
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
     * The copy lives in UpstreamRefusal so services can use it too.
     */
    protected function upstreamRefusedMessage(ServiceType $serviceType, ?User $user): string
    {
        return UpstreamRefusal::message($serviceType, $user);
    }

    /**
     * The library pages' Sync button: bust the connection's cached reads (at
     * most once per LibraryCacheSync::DECAY_SECONDS per connection, whoever
     * asks) and go back to the page, which reloads its lists.
     */
    protected function syncLibraryCache(ServiceConnection $serviceConnection, LibraryCacheSync $libraryCacheSync): RedirectResponse
    {
        $service = $serviceConnection->type->label();

        if ($libraryCacheSync->sync($serviceConnection)) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Synced with :service.', ['service' => $service])]);
        } else {
            Inertia::flash('toast', ['type' => 'info', 'message' => __(':service was synced moments ago — try again in :seconds s.', [
                'service' => $service,
                'seconds' => $libraryCacheSync->secondsUntilNextSync($serviceConnection),
            ])]);
        }

        return back();
    }
}
