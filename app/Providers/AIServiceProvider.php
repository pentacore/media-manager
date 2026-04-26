<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\Ai\RecordAgentFailover;
use App\Listeners\Ai\RecordAgentUsage;
use App\Listeners\Ai\RecordToolInvocation;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\ProviderFailedOver;
use Laravel\Ai\Events\ToolInvoked;
use Override;

class AIServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->singleton('mediamanager.ai.enabled', fn (Application $application): bool => (bool) $application->make('config')->get('mediamanager.ai.enabled', false));
    }

    public function boot(): void
    {
        if (! $this->aiEnabled()) {
            return;
        }

        Event::listen(AgentPrompted::class, RecordAgentUsage::class);
        Event::listen(ToolInvoked::class, RecordToolInvocation::class);
        Event::listen(AgentFailedOver::class, RecordAgentFailover::class);
        Event::listen(ProviderFailedOver::class, RecordAgentFailover::class);
    }

    public static function enabled(): bool
    {
        return (bool) config('mediamanager.ai.enabled', false);
    }

    private function aiEnabled(): bool
    {
        return (bool) $this->app->make('mediamanager.ai.enabled');
    }
}
