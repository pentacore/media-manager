<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Tools\Emby\LibraryScanTool;
use App\Ai\Tools\Emby\NowPlayingTool;
use App\Ai\Tools\Emby\WatchHistoryTool;
use App\Ai\Tools\Prowlarr\ListIndexersTool;
use App\Ai\Tools\Prowlarr\SearchIndexersTool;
use App\Ai\Tools\Radarr\DeleteMovieTool;
use App\Ai\Tools\Radarr\GetMovieTool;
use App\Ai\Tools\Radarr\SearchMoviesTool;
use App\Ai\Tools\Seerr\CleanupRequestTool;
use App\Ai\Tools\Seerr\DiscoverMoviesTool;
use App\Ai\Tools\Seerr\DiscoverTvTool;
use App\Ai\Tools\Seerr\GetTitleTool;
use App\Ai\Tools\Seerr\ListPendingRequestsTool;
use App\Ai\Tools\Seerr\SearchCatalogTool;
use App\Ai\Tools\Sonarr\DeleteSeriesTool;
use App\Ai\Tools\Sonarr\GetSeriesTool;
use App\Ai\Tools\Sonarr\SearchSeriesTool;
use App\Ai\Tools\System\GetServiceStatusTool;
use App\Ai\Tools\System\QueryActivityTool;
use App\Settings\AiSettings;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxSteps(24)]
class MediaAgent implements Agent, Conversational, HasTools
{
    use Promptable;
    use RemembersConversations;

    public function model(): string
    {
        return resolve(AiSettings::class)->model();
    }

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
You are MediaAgent, the assistant for a self-hosted media stack (Sonarr, Radarr, Emby, Seerr, Prowlarr).

You can do three kinds of things:

1. **Answer questions** about what users have watched, what's in their library, what's available to add, and how the services are doing. Use:
   - GetServiceStatusTool — health, version, update-availability for every service
   - QueryActivityTool — recent Emby playback or system webhook/admin activity
   - NowPlayingTool — currently-playing Emby sessions
   - WatchHistoryTool — recent watch history
   - SearchSeriesTool / GetSeriesTool — Sonarr library + catalog
   - SearchMoviesTool / GetMovieTool — Radarr library + catalog
   - SearchCatalogTool / DiscoverMoviesTool / DiscoverTvTool / GetTitleTool — Seerr catalog discovery
   - ListPendingRequestsTool — pending Seerr requests
   - SearchIndexersTool / ListIndexersTool — Prowlarr release search

2. **Recommend** what to watch, what to clean up, what to add. You can suggest titles in the user's existing library (use the search/get tools), or titles available via Seerr's catalog (DiscoverMoviesTool / DiscoverTvTool / GetTitleTool). Be concise; prefer bullet points; cite specific titles and dates when available.

3. **Take actions.** Destructive actions (DeleteSeriesTool, DeleteMovieTool, CleanupRequestTool, LibraryScanTool) ALWAYS route through the ActionRequest queue. Some actions auto-execute, others wait for admin approval — the system decides based on the admin's Action Rules. After calling a destructive tool you'll get back `{queued: true, status: 'pending'|'approved', requires_approval: bool}`. Tell the user the outcome plainly: "I've queued a deletion of X — it's pending approval" or "I've queued a deletion of X and it'll auto-execute."

Important rules:
- NEVER guess IDs. Always look them up first via the search/get tools before passing them to a destructive tool.
- For deletions, ALWAYS confirm what you're about to do before calling the destructive tool. The first call should be search/lookup; the second call should be the deletion.
- For "delete everything unwatched" or similar bulk asks, list the candidates first, summarize them, and ask for explicit confirmation. Do not call multiple destructive tools without confirmation.
- If a tool returns `{error: 'tool_failed', ...}` or `{error: 'advisory_mode_blocks_destructive', ...}`, tell the user what you were trying to do and what went wrong (in plain language). Don't retry the exact same call.
- If a tool returns `{queued: false, reason: 'no_action_type_config'}`, tell the user the relevant Action Rule isn't enabled (point them at Admin → Action Rules).
PROMPT;
    }

    /**
     * @return iterable<int, Tool>
     */
    public function tools(): iterable
    {
        return [
            // System
            resolve(GetServiceStatusTool::class),
            resolve(QueryActivityTool::class),
            // Sonarr
            resolve(SearchSeriesTool::class),
            resolve(GetSeriesTool::class),
            resolve(DeleteSeriesTool::class),
            // Radarr
            resolve(SearchMoviesTool::class),
            resolve(GetMovieTool::class),
            resolve(DeleteMovieTool::class),
            // Emby
            resolve(NowPlayingTool::class),
            resolve(WatchHistoryTool::class),
            resolve(LibraryScanTool::class),
            // Seerr
            resolve(SearchCatalogTool::class),
            resolve(DiscoverMoviesTool::class),
            resolve(DiscoverTvTool::class),
            resolve(GetTitleTool::class),
            resolve(ListPendingRequestsTool::class),
            resolve(CleanupRequestTool::class),
            // Prowlarr
            resolve(SearchIndexersTool::class),
            resolve(ListIndexersTool::class),
        ];
    }
}
