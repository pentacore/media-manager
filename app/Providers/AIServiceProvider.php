<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AIServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->singleton('mediamanager.ai.enabled', fn (Application $application): bool => (bool) $application->make('config')->get('mediamanager.ai.enabled', false));
    }

    public function boot(): void
    {
        if (! $this->aiEnabled()) {
            return;
        }

        // Future tasks (Phase 9 Tasks 2-4) will register agents and tools here,
        // e.g. via Laravel AI SDK's agent/tool registry. Nothing to wire yet.
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
