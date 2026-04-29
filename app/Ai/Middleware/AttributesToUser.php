<?php

declare(strict_types=1);

namespace App\Ai\Middleware;

use App\Models\User;
use Closure;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StreamableAgentResponse;

/**
 * Stamps the resolved AgentResponse with the user that triggered the run so
 * RecordAgentUsage can attribute spend correctly. Unlike RememberConversation
 * this does NOT persist a conversation row — appropriate for one-shot agents
 * (e.g. PriceFetcherAgent) invoked from a queued job, where Auth::id() is
 * unavailable.
 */
class AttributesToUser
{
    public function __construct(public User $user) {}

    public function handle(AgentPrompt $agentPrompt, Closure $next)
    {
        return $next($agentPrompt)->then(function (AgentResponse|StreamableAgentResponse $response): void {
            $response->conversationUser = $this->user;
        });
    }
}
