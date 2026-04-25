<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
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
        }
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
