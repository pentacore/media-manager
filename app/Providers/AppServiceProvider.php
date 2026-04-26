<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\ActionRequestCreated;
use App\Events\ActionRequestStatusChanged;
use App\Events\ServiceHealthChanged;
use App\Events\WebhookReceived;
use App\Listeners\RebroadcastDashboardStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Override;
use SocialiteProviders\Authentik\AuthentikExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[Override]
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        Event::listen(SocialiteWasCalled::class, AuthentikExtendSocialite::class);

        // Pipe the four high-signal upstream events through the throttled
        // dashboard-stats listener so the four counters stay current without
        // waiting for the every-5-minute cron.
        foreach ([
            WebhookReceived::class,
            ActionRequestCreated::class,
            ActionRequestStatusChanged::class,
            ServiceHealthChanged::class,
        ] as $event) {
            Event::listen($event, RebroadcastDashboardStats::class);
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
