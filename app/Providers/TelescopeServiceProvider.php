<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;
use Override;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    #[Override]
    public function register(): void
    {
        // Telescope::night();

        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

        Telescope::filter(fn (IncomingEntry $incomingEntry): bool => $isLocal ||
               $incomingEntry->isReportableException() ||
               $incomingEntry->isFailedRequest() ||
               $incomingEntry->isFailedJob() ||
               $incomingEntry->isScheduledTask() ||
               $incomingEntry->hasMonitoredTag());
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        if ($this->app->environment('local')) {
            return;
        }

        Telescope::hideRequestParameters(['_token']);

        Telescope::hideRequestHeaders([
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);
    }

    /**
     * Register the Telescope gate.
     *
     * This gate determines who can access Telescope in non-local environments.
     */
    #[Override]
    protected function gate(): void
    {
        Gate::define('viewTelescope', fn (User $user): bool => in_array($user->email, [
            //
        ]));
    }
}
