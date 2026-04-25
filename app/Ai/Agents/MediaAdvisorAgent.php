<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Tools\GetServiceStatusTool;
use App\Ai\Tools\QueryActivityTool;
use App\Ai\Tools\SearchMediaTool;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider(Lab::Anthropic)]
#[MaxSteps(8)]
class MediaAdvisorAgent implements Agent, Conversational, HasTools
{
    use Promptable;
    use RemembersConversations;

    /**
     * Get the model that the agent should use.
     */
    public function model(): string
    {
        return (string) config('mediamanager.ai.model', 'claude-sonnet-4-5');
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
You are MediaAdvisor, an assistant for a self-hosted media stack (Sonarr, Radarr, Emby, Seerr).

Your role:
- Answer questions about what users have watched and recommend what to watch next.
- Summarize library contents and identify unwatched or stale series that might be candidates for deletion.
- Report service health and version status when asked.
- When suggesting deletions or cleanups, describe the recommended action clearly but DO NOT create ActionRequests yourself — that's CommandAgent's job. Instead, tell the user what you recommend and suggest they run the Command assistant if they want to act.

Available tools:
- SearchMediaTool — search Sonarr/Radarr/Seerr catalogs.
- QueryActivityTool — read recent Emby playback and system activity logs.
- GetServiceStatusTool — snapshot health, versions, update availability.

Be concise. Use bullet points for lists. Cite specific titles and dates when available.
PROMPT;
    }

    /**
     * Get the tools available to the agent.
     *
     * @return iterable<int, Tool>
     */
    public function tools(): iterable
    {
        return [
            resolve(SearchMediaTool::class),
            resolve(QueryActivityTool::class),
            resolve(GetServiceStatusTool::class),
        ];
    }
}
