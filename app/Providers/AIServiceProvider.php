<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ai\Storage\HealingConversationStore;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Contracts\ConversationStore;
use Override;

class AIServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->singleton('mediamanager.ai.enabled', fn (Application $application): bool => (bool) $application->make('config')->get('mediamanager.ai.enabled', false));

        $this->app->extend(
            ConversationStore::class,
            fn (ConversationStore $conversationStore): HealingConversationStore => new HealingConversationStore($conversationStore),
        );
    }

    // Note: RecordAgentUsage / RecordToolInvocation / RecordAgentFailover are
    // wired to their events via Laravel's automatic listener discovery
    // (enabled by default in bootstrap/app.php's withEvents() call).
    // Explicit Event::listen registrations here used to double-bind every
    // listener and produce duplicate ai_usage_records rows per call.

    public static function enabled(): bool
    {
        return (bool) config('mediamanager.ai.enabled', false);
    }
}
