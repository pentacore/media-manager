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
     * Prevent sensitive request details from being logged by Telescope. Agent credentials (the bearer token, the
     * enrollment token and the agent token it is exchanged for, in the request and in the enrollment response) and the outside watcher's push URL, whose token is
     * part of the URL, are hidden in every environment: Telescope is only registered locally, where a real agent
     * may still report.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        Telescope::hideRequestHeaders(['authorization']);
        Telescope::hideRequestParameters(['enrollment_token', 'agent_token', 'push_url']);
        Telescope::hideResponseParameters(['agent_token']);

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
