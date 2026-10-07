<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ai\AiRunAttribution;
use App\Ai\ChatTurnContext;
use App\Ai\ReasoningOptions;
use App\Ai\TaskModelResolver;
use App\Http\Streaming\ClientConnection;
use App\Listeners\Ai\EnforceAiRateLimit;
use App\Listeners\Ai\RecordAgentUsage;
use App\Services\AiUsage\AiUsageCaller;
use App\Services\AiUsage\BatchPricingContext;
use App\Services\AiUsage\Pricing\InUsePricingModels;
use App\Services\AiUsage\Pricing\PriceRefreshTimeBox;
use App\Services\AiUsage\RunUsageAccumulator;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\StreamingAgent;
use Override;

class AIServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->singleton('mediamanager.ai.enabled', fn (Application $application): bool => (bool) $application->make('config')->get('mediamanager.ai.enabled', false));

        $this->app->scoped(BatchPricingContext::class);

        // Holds the user who triggered the in-flight AI run; scoped so it
        // cannot leak into the next Octane request or queued job.
        $this->app->scoped(AiRunAttribution::class);

        // The conversation override for the chat turn in flight; scoped so it
        // never applies to the next Octane request or queued job.
        $this->app->scoped(ChatTurnContext::class);

        // Memoises ai_task_models for the request; scoped for the same reason.
        $this->app->scoped(TaskModelResolver::class);

        // Memoises the price rows it reads reasoning capabilities from; scoped
        // so a long-running worker never maps against stale capabilities.
        $this->app->scoped(ReasoningOptions::class);

        // Per-run step usage keyed by invocation id; scoped so partial runs
        // never bleed into the next Octane request or queued job.
        $this->app->scoped(RunUsageAccumulator::class);

        // Caller label for the in-flight embeddings/reranking call; scoped so
        // it never mislabels the next Octane request or queued job.
        $this->app->scoped(AiUsageCaller::class);

        // Memoizes the in-use provider/model map; scoped so a long-running
        // worker never prices against another request's or job's usage.
        $this->app->scoped(InUsePricingModels::class);

        // The queued price refresh's deadline; scoped so one job's time box
        // never limits the next job or an Octane request.
        $this->app->scoped(PriceRefreshTimeBox::class);

        // Whether the current chat stream's browser is still connected;
        // scoped so one request's watch flag never carries into the next
        // Octane request.
        $this->app->scoped(ClientConnection::class);
    }

    // Note: RecordAgentUsage / RecordToolInvocation / RecordAgentFailover are
    // wired to their events via Laravel's default event discovery, which scans
    // app/Listeners for type-hinted handlers (on by default; no withEvents()
    // call in bootstrap/app.php is needed). Explicit Event::listen
    // registrations here used to double-bind every listener and produce
    // duplicate ai_usage_records rows per call.
    //
    // Exception: streamed runs. The real streaming gateway dispatches only
    // AgentStreamed (never AgentPrompted), and discovery binds listeners to
    // the exact type-hinted class — the dispatcher does not walk parent
    // classes. Without this registration, streamed chat turns record no
    // usage and the budget guard goes blind. RecordAgentUsage dedupes by
    // invocation_id, so environments that dispatch both events (the fake
    // gateway) still produce exactly one row.
    //
    // EnforceAiRateLimit has the same shape: discovery binds it to
    // PromptingAgent, and the streaming gateway dispatches only the
    // StreamingAgent subclass, which needs its own registration.
    public function boot(): void
    {
        Event::listen(AgentStreamed::class, RecordAgentUsage::class);
        Event::listen(StreamingAgent::class, EnforceAiRateLimit::class);
    }

    public static function enabled(): bool
    {
        return (bool) config('mediamanager.ai.enabled', false);
    }
}
