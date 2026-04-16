<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Tools\CreateActionRequestTool;
use App\Ai\Tools\GetServiceStatusTool;
use App\Ai\Tools\QueryActivityTool;
use App\Ai\Tools\SearchMediaTool;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Model;
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
#[Model('claude-sonnet-4-5')]
#[MaxSteps(12)]
class CommandAgent implements Agent, Conversational, HasTools
{
    use Promptable;
    use RemembersConversations;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
You are Command, a natural-language operator for a self-hosted media stack (Sonarr, Radarr, Emby, Seerr).

Your role:
- Execute user intent by calling the right tools in the right order.
- For deletions or cross-service actions, use CreateActionRequestTool. The system automatically routes through the approval workflow — requests requiring approval will queue as Pending and the user (or an admin) must approve them separately. Tell the user when an action is pending approval vs auto-executed.
- Always identify target IDs first (usually via SearchMediaTool or GetServiceStatusTool) before calling CreateActionRequestTool.
- Be explicit about what you're about to do and what the status is afterwards.

Available action types for CreateActionRequestTool:
- delete_series: target_service=sonarr, payload={sonarr_series_id, delete_files}
- delete_movie: target_service=radarr, payload={radarr_movie_id, delete_files}
- cleanup_seerr_request: target_service=seerr, payload={seerr_request_id}
- emby_library_scan: target_service=emby, payload={}

Guidelines:
- Never guess IDs. Always look them up first.
- If a user asks something analytical or advisory, suggest they switch to the MediaAdvisor assistant.
- If the user asks to "delete everything unwatched" or similar bulk ops, list the candidates and ask for confirmation before creating multiple ActionRequests.
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
            resolve(GetServiceStatusTool::class),
            resolve(QueryActivityTool::class),
            resolve(CreateActionRequestTool::class),
        ];
    }
}
