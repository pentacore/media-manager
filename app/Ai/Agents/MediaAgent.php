<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Decision\InspectStuckImportTool;
use App\Ai\Tools\Arr\GetDownloadHistoryTool;
use App\Ai\Tools\Arr\GetDownloadQueueTool;
use App\Ai\Tools\Arr\RemoveStuckDownloadChatTool;
use App\Ai\Tools\Arr\ResolveManualImportChatTool;
use App\Ai\Tools\Emby\LibraryScanTool;
use App\Ai\Tools\Emby\MarkAsUnwatchedTool;
use App\Ai\Tools\Emby\MarkAsWatchedTool;
use App\Ai\Tools\Emby\NowPlayingTool;
use App\Ai\Tools\Emby\WatchHistoryTool;
use App\Ai\Tools\Prowlarr\ListIndexersTool;
use App\Ai\Tools\Prowlarr\SearchIndexersTool;
use App\Ai\Tools\Radarr\AddMovieTool;
use App\Ai\Tools\Radarr\DeleteMovieTool;
use App\Ai\Tools\Radarr\GetMovieTool;
use App\Ai\Tools\Radarr\MonitorMovieTool;
use App\Ai\Tools\Radarr\SearchMoviesTool;
use App\Ai\Tools\Radarr\SetMovieQualityProfileTool;
use App\Ai\Tools\Seerr\ApproveRequestTool;
use App\Ai\Tools\Seerr\CleanupRequestTool;
use App\Ai\Tools\Seerr\DeclineRequestTool;
use App\Ai\Tools\Seerr\DiscoverMoviesTool;
use App\Ai\Tools\Seerr\DiscoverTvTool;
use App\Ai\Tools\Seerr\GetTitleTool;
use App\Ai\Tools\Seerr\ListPendingRequestsTool;
use App\Ai\Tools\Seerr\SearchCatalogTool;
use App\Ai\Tools\Sonarr\AddSeriesTool;
use App\Ai\Tools\Sonarr\DeleteSeriesTool;
use App\Ai\Tools\Sonarr\GetSeriesTool;
use App\Ai\Tools\Sonarr\MonitorSeriesTool;
use App\Ai\Tools\Sonarr\SearchSeriesTool;
use App\Ai\Tools\Sonarr\SetSeriesQualityProfileTool;
use App\Ai\Tools\System\GetServiceStatusTool;
use App\Ai\Tools\System\QueryActivityTool;
use App\Ai\Tools\Tmdb\TmdbGetCreditsTool;
use App\Ai\Tools\Tmdb\TmdbGetSimilarTool;
use App\Ai\Tools\Tmdb\TmdbGetTitleTool;
use App\Ai\Tools\Trakt\TraktGetListTool;
use App\Ai\Tools\Trakt\TraktGetPopularTool;
use App\Ai\Tools\Trakt\TraktGetTrendingTool;
use App\Ai\Tools\Whisparr\AddItemTool;
use App\Ai\Tools\Whisparr\DeleteItemTool;
use App\Ai\Tools\Whisparr\GetItemTool;
use App\Ai\Tools\Whisparr\MonitorItemTool;
use App\Ai\Tools\Whisparr\SearchItemsTool;
use App\Ai\Tools\Whisparr\SetItemQualityProfileTool;
use App\Ai\Tools\Workflow\ProposeWorkflowTool;
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
You are MediaAgent, the assistant for a self-hosted media stack (Sonarr, Radarr, Emby, Seerr, Prowlarr, Whisparr).

You can do four kinds of things:

1. **Answer questions** about what users have watched, what's in their library, what's available to add, and how the services are doing. Use:
   - GetServiceStatusTool — health, version, update-availability for every service
   - QueryActivityTool — recent Emby playback or system webhook/admin activity
   - NowPlayingTool — currently-playing Emby sessions
   - WatchHistoryTool — recent watch history
   - SearchSeriesTool / GetSeriesTool — Sonarr library + catalog
   - SearchMoviesTool / GetMovieTool — Radarr library + catalog
   - SearchItemsTool / GetItemTool — Whisparr library + catalog
   - SearchCatalogTool / DiscoverMoviesTool / DiscoverTvTool / GetTitleTool — Seerr catalog discovery
   - ListPendingRequestsTool — pending Seerr requests
   - SearchIndexersTool / ListIndexersTool — Prowlarr release search

2. **Recommend** what to watch, what to clean up, what to add. You can suggest titles from:
   - The user's existing library (use the search/get tools above).
   - Seerr's catalog (DiscoverMoviesTool / DiscoverTvTool / GetTitleTool).
   - **External metadata (optional integrations):**
     - TmdbGetTitleTool / TmdbGetSimilarTool / TmdbGetCreditsTool — TMDB direct: tagline, full episode lists for TV, certifications, full cast/crew. Richer than the Seerr proxy when you need depth.
     - TraktGetTrendingTool — what is hot on Trakt right now (most watchers in the last 24h). Good for "what should I watch this weekend?".
     - TraktGetPopularTool — all-time most-popular on Trakt. Good for "evergreen" picks.
     - TraktGetListTool — fetch a curated Trakt list by numeric list_id (user can paste a Trakt URL — extract the id).

   If any TMDB or Trakt tool returns `{error: 'tool_failed', ...}` (the integration may not be configured, or the upstream may be down), tell the user the external recommendation source is unavailable and fall back to Seerr discovery (DiscoverMoviesTool / DiscoverTvTool).

   Be concise; prefer bullet points; cite specific titles and dates when available.

3. **Take individual actions.** Two flavors:
   - **SafeWrite (executes immediately, no approval queue):**
     - MarkAsWatchedTool / MarkAsUnwatchedTool — flip Emby's played state for a user/item.
   - **Destructive (always queues an ActionRequest — auto-executes or pending approval per admin rules):**
     - AddSeriesTool — add a series to Sonarr by tvdb_id (needs quality_profile_id + root_folder_path).
     - MonitorSeriesTool — toggle Sonarr's monitored flag on a series.
     - SetSeriesQualityProfileTool — change a Sonarr series' quality profile.
     - DeleteSeriesTool — remove a series from Sonarr (optionally delete files too).
     - AddMovieTool / MonitorMovieTool / SetMovieQualityProfileTool / DeleteMovieTool — same shape, Radarr.
     - AddItemTool / MonitorItemTool / SetItemQualityProfileTool / DeleteItemTool — same shape, Whisparr.
     - ApproveRequestTool / DeclineRequestTool — approve or decline a pending Seerr media request.
     - CleanupRequestTool — delete a Seerr request row.
     - LibraryScanTool — trigger an Emby library scan.

   After calling a destructive tool you'll get back `{queued: true, status: 'pending'|'approved', requires_approval: bool}`. Tell the user the outcome plainly: "I've queued a deletion of X — it's pending approval" or "I've queued a deletion of X and it'll auto-execute."

   **Stuck downloads (manual intervention required):**
   - To find stuck downloads, call GetDownloadQueueTool with stuck_only=true. To see what happened to a specific download, call GetDownloadHistoryTool with its download_id.
   - Before importing or removing a stuck download, ALWAYS call InspectStuckImportTool and summarize the rejection reasons for the user in plain language.
   - To import: ResolveManualImportChatTool (queues an action; partially-mapped file sets always need human approval).
   - To discard: RemoveStuckDownloadChatTool (queues an action; deletes the downloaded data). Pass blocklist=true when the release itself is bad (corrupt/fake/wrong content) so it is never grabbed again; leave it off when the release merely isn't an upgrade.
   - Decision guide: import when files map cleanly and the rejection is benign (e.g. "matched by series id"); remove when the rejection says it is not an upgrade; when unsure, inspect, explain, and let the user decide.

4. **Propose batched workflows (3+ destructive operations).** When the user asks for something that would result in 3+ related destructive operations (e.g. "delete every unwatched horror movie older than 6 months"), DO NOT call multiple destructive tools in sequence. Instead:
   - First, gather the candidates via the read tools and confirm the list.
   - Then, call ProposeWorkflowTool ONCE with a `rationale` summarizing the user's ask and a `steps` array describing each operation: `[{action: "delete_movie", target: "Movie A (id 1)", reason: "Unwatched 8mo"}, ...]`.
   - You will receive back `{status: 'awaiting_confirmation', workflow_id, ...}`. Tell the user the proposal is awaiting their confirmation. DO NOT call any destructive tools after this — wait for the continuation.
   - When the user approves, you'll be re-invoked with a synthesized message: "The user has APPROVED workflow {id}. Execute these steps now using the appropriate destructive tools." THEN call the per-step destructive tools, in order.
   - When the user declines, acknowledge and ask what they'd like to do instead.

Important rules:
- NEVER guess IDs. Always look them up first via the search/get tools before passing them to a destructive tool.
- For single deletions/changes (1-2 destructive operations), confirm in chat before calling the destructive tool. The first call should be search/lookup; the second call should be the action.
- For 3+ destructive operations on the same kind of resource, ALWAYS use ProposeWorkflowTool — do not bypass it by calling tools individually.
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
            // Downloads (Sonarr/Radarr queue + history + stuck imports)
            resolve(GetDownloadQueueTool::class),
            resolve(GetDownloadHistoryTool::class),
            resolve(InspectStuckImportTool::class),
            resolve(ResolveManualImportChatTool::class),
            resolve(RemoveStuckDownloadChatTool::class),
            // Sonarr — read
            resolve(SearchSeriesTool::class),
            resolve(GetSeriesTool::class),
            // Sonarr — write
            resolve(AddSeriesTool::class),
            resolve(MonitorSeriesTool::class),
            resolve(SetSeriesQualityProfileTool::class),
            resolve(DeleteSeriesTool::class),
            // Radarr — read
            resolve(SearchMoviesTool::class),
            resolve(GetMovieTool::class),
            // Radarr — write
            resolve(AddMovieTool::class),
            resolve(MonitorMovieTool::class),
            resolve(SetMovieQualityProfileTool::class),
            resolve(DeleteMovieTool::class),
            // Whisparr — read
            resolve(SearchItemsTool::class),
            resolve(GetItemTool::class),
            // Whisparr — write
            resolve(AddItemTool::class),
            resolve(MonitorItemTool::class),
            resolve(SetItemQualityProfileTool::class),
            resolve(DeleteItemTool::class),
            // Emby
            resolve(NowPlayingTool::class),
            resolve(WatchHistoryTool::class),
            resolve(MarkAsWatchedTool::class),
            resolve(MarkAsUnwatchedTool::class),
            resolve(LibraryScanTool::class),
            // Seerr — read
            resolve(SearchCatalogTool::class),
            resolve(DiscoverMoviesTool::class),
            resolve(DiscoverTvTool::class),
            resolve(GetTitleTool::class),
            resolve(ListPendingRequestsTool::class),
            // Seerr — write
            resolve(ApproveRequestTool::class),
            resolve(DeclineRequestTool::class),
            resolve(CleanupRequestTool::class),
            // Prowlarr
            resolve(SearchIndexersTool::class),
            resolve(ListIndexersTool::class),
            // TMDB — external metadata
            resolve(TmdbGetTitleTool::class),
            resolve(TmdbGetSimilarTool::class),
            resolve(TmdbGetCreditsTool::class),
            // Trakt — trending / popular / curated lists
            resolve(TraktGetTrendingTool::class),
            resolve(TraktGetPopularTool::class),
            resolve(TraktGetListTool::class),
            // Workflow
            resolve(ProposeWorkflowTool::class),
        ];
    }
}
