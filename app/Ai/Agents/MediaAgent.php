<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Decision\InspectStuckImportTool;
use App\Ai\Tools\Arr\AddMediaTool;
use App\Ai\Tools\Arr\DeleteMediaTool;
use App\Ai\Tools\Arr\FindReplacementCandidatesTool;
use App\Ai\Tools\Arr\GetDownloadHistoryTool;
use App\Ai\Tools\Arr\GetDownloadQueueTool;
use App\Ai\Tools\Arr\GetMediaTool;
use App\Ai\Tools\Arr\InspectMediaFileTool;
use App\Ai\Tools\Arr\MonitorMediaTool;
use App\Ai\Tools\Arr\RemoveStuckDownloadChatTool;
use App\Ai\Tools\Arr\ReplaceMediaFileTool;
use App\Ai\Tools\Arr\ResolveManualImportChatTool;
use App\Ai\Tools\Arr\SearchMediaTool;
use App\Ai\Tools\Arr\SetMediaQualityProfileTool;
use App\Ai\Tools\Bazarr\InspectSubtitleTool;
use App\Ai\Tools\Bazarr\RequestSubtitleOperationTool;
use App\Ai\Tools\Bazarr\SearchSubtitlesTool;
use App\Ai\Tools\Emby\LibraryScanTool;
use App\Ai\Tools\Emby\MarkAsUnwatchedTool;
use App\Ai\Tools\Emby\MarkAsWatchedTool;
use App\Ai\Tools\Emby\NowPlayingTool;
use App\Ai\Tools\Emby\WatchHistoryTool;
use App\Ai\Tools\Prowlarr\ListIndexersTool;
use App\Ai\Tools\Prowlarr\SearchIndexersTool;
use App\Ai\Tools\Seerr\ApproveRequestTool;
use App\Ai\Tools\Seerr\CleanupRequestTool;
use App\Ai\Tools\Seerr\DeclineRequestTool;
use App\Ai\Tools\Seerr\DiscoverMoviesTool;
use App\Ai\Tools\Seerr\DiscoverTvTool;
use App\Ai\Tools\Seerr\GetTitleTool;
use App\Ai\Tools\Seerr\ListPendingRequestsTool;
use App\Ai\Tools\Seerr\SearchCatalogTool;
use App\Ai\Tools\System\GetServiceStatusTool;
use App\Ai\Tools\System\QueryActivityTool;
use App\Ai\Tools\System\SemanticLibrarySearchTool;
use App\Ai\Tools\Tmdb\TmdbGetCreditsTool;
use App\Ai\Tools\Tmdb\TmdbGetSimilarTool;
use App\Ai\Tools\Tmdb\TmdbGetTitleTool;
use App\Ai\Tools\Trakt\TraktGetListTool;
use App\Ai\Tools\Trakt\TraktGetPopularTool;
use App\Ai\Tools\Trakt\TraktGetTrendingTool;
use App\Ai\Tools\Workflow\ProposeWorkflowTool;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxSteps(24)]
class MediaAgent implements Agent, Conversational, HasProviderOptions, HasTools
{
    use Promptable;
    use RemembersConversations;

    public function model(): string
    {
        return resolve(AiSettings::class)->model();
    }

    /**
     * Seconds to wait on the provider before aborting a turn. The SDK picks
     * this up via Promptable::getTimeout() for both prompt() and stream(), in
     * preference to its own default. A chat turn can chain many tool
     * round-trips (MaxSteps is 24), so the default here is far more generous
     * than a single-request timeout — admins tune it in Admin → AI Settings.
     */
    public function timeout(): int
    {
        return resolve(AiSettings::class)->chatTimeout();
    }

    public function providerOptions(Lab|string $provider): array
    {
        $options = match ($provider) {
            Lab::OpenAI => [
                'reasoning' => ['effort' => resolve(AiSettings::class)->advisorReasoningLevel()],
            ],
            default => [],
        };
        Log::debug('MediaAgent provider options', ['provider' => $provider, 'options' => $options]);

        return $options;
    }

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
You are MediaAgent, the assistant for a self-hosted media stack (Sonarr, Radarr, Emby, Seerr, Prowlarr, Whisparr).

The media library tools (SearchMediaTool, GetMediaTool, AddMediaTool, DeleteMediaTool, MonitorMediaTool, SetMediaQualityProfileTool) take a `service` parameter: sonarr = TV series, radarr = movies, whisparr = adult content. If a tool fails because a service is not configured, say so plainly.

**Answering questions:** use the read tools. For similarity / vibe-based library questions ("something like Dark but lighter", "cozy detective shows"), prefer SemanticLibrarySearchTool over keyword filters. It returns library items only; if it reports `available: false`, fall back to GetMediaTool/SearchMediaTool.

**Recommendations:** suggest from the user's library, Seerr discovery (DiscoverMoviesTool / DiscoverTvTool / GetTitleTool), or the optional TMDB (depth: taglines, episode lists, cast/crew) and Trakt (trending, popular, curated lists — user can paste a list URL, extract the numeric id) tools when available. If a TMDB/Trakt tool returns `{error: 'tool_failed', ...}`, tell the user the external source is unavailable and fall back to Seerr discovery. Be concise; prefer bullet points; cite specific titles and dates when available.

**Actions:**
- SafeWrite (executes immediately, no approval queue): MarkAsWatchedTool / MarkAsUnwatchedTool.
- Destructive (always queues an ActionRequest — auto-executes or pending approval per admin rules): the add/delete/monitor/quality-profile media tools, Seerr ApproveRequestTool / DeclineRequestTool / CleanupRequestTool, and LibraryScanTool. After calling one you get back `{queued: true, status: 'pending'|'approved', requires_approval: bool}`. Tell the user the outcome plainly: "I've queued a deletion of X — it's pending approval" or "…and it'll auto-execute."

**Stuck downloads (manual intervention required):**
- Find them via GetDownloadQueueTool with stuck_only=true; see what happened to a specific download via GetDownloadHistoryTool with its download_id.
- Before importing or removing a stuck download, ALWAYS call InspectStuckImportTool and summarize the rejection reasons for the user in plain language.
- Import via ResolveManualImportChatTool (partially-mapped file sets always need human approval). Discard via RemoveStuckDownloadChatTool: pass blocklist=true when the release itself is bad (corrupt/fake/wrong content) so it is never grabbed again; pass search_replacement=true when a replacement should be grabbed.
- Decision guide: import when files map cleanly and the rejection is benign (e.g. "matched by series id"); remove when the rejection says it is not an upgrade; when unsure, inspect, explain, and let the user decide.

**Replacing imported media with missing/incorrect subtitles:**
- Resolve IDs, then call InspectMediaFileTool. Never guess a series, episode, movie, or file id. If it returns ambiguous=true, present the choices/affected episodes and ask the user which target they mean.
- Call FindReplacementCandidatesTool with the user's language override, or null to use configured defaults.
- If automatic_candidate is present, you may select exactly that fingerprint. Otherwise present the ranked candidates and wait for the user to choose.
- Before ReplaceMediaFileTool, state every affected episode/file. Season packs may replace multiple files.
- A queued/completed ActionRequest means replacement was requested/initiated, not fixed. Say subtitles are fixed only when verification reports verified.
- Do not retry a failed replacement autonomously.

**Bazarr subtitles:** when Bazarr is configured, inspect one exact item with InspectSubtitleTool before searching. SearchSubtitlesTool returns opaque candidate fingerprints only. RequestSubtitleOperationTool is destructive and always follows Action Rules; never claim a queued operation has already fixed the subtitle.

**Batched workflows (3+ destructive operations):** DO NOT call multiple destructive tools in sequence. Gather the candidates via the read tools and confirm the list, then call ProposeWorkflowTool ONCE with a `rationale` and a `steps` array: `[{action: "delete_movie", target: "Movie A (id 1)", reason: "Unwatched 8mo"}, ...]`. You'll get back `{status: 'awaiting_confirmation', workflow_id, ...}` — tell the user the proposal awaits their confirmation and call NO destructive tools until the continuation. When re-invoked with "The user has APPROVED workflow {id}…", execute the steps with the destructive tools, in order. On decline, acknowledge and ask what they'd like instead.

Important rules:
- NEVER guess IDs. Always look them up first via the search/get tools before passing them to a destructive tool.
- For 1-2 destructive operations, confirm in chat before calling the destructive tool: first call is search/lookup, second is the action.
- For 3+ destructive operations on the same kind of resource, ALWAYS use ProposeWorkflowTool — never bypass it by calling tools individually.
- If a tool returns `{error: 'tool_failed', ...}` or `{error: 'advisory_mode_blocks_destructive', ...}`, tell the user what you were trying to do and what went wrong in plain language. Always repeat the `detail` field when one is present — it is the only place the user sees why it failed, short of reading the server logs. Don't retry the exact same call.
- If a tool returns `{queued: false, reason: 'no_action_type_config'}`, tell the user the relevant Action Rule isn't enabled (Admin → Action Rules).
PROMPT;
    }

    /**
     * Optional integrations only contribute their tools when configured —
     * every schema the model never sees is base-prompt tokens saved.
     *
     * @return iterable<int, Tool>
     */
    public function tools(): iterable
    {
        $tools = [
            // System
            resolve(GetServiceStatusTool::class),
            resolve(QueryActivityTool::class),
            resolve(SemanticLibrarySearchTool::class),
            // Downloads (Sonarr/Radarr queue + history + stuck imports)
            resolve(GetDownloadQueueTool::class),
            resolve(GetDownloadHistoryTool::class),
            resolve(InspectStuckImportTool::class),
            resolve(ResolveManualImportChatTool::class),
            resolve(RemoveStuckDownloadChatTool::class),
            // Media library (Sonarr/Radarr/Whisparr via `service` param)
            resolve(SearchMediaTool::class),
            resolve(GetMediaTool::class),
            resolve(AddMediaTool::class),
            resolve(MonitorMediaTool::class),
            resolve(SetMediaQualityProfileTool::class),
            resolve(DeleteMediaTool::class),

            resolve(InspectMediaFileTool::class),
            resolve(FindReplacementCandidatesTool::class),
            resolve(ReplaceMediaFileTool::class),
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
            // Workflow
            resolve(ProposeWorkflowTool::class),
        ];

        if ($this->hasActiveConnection(ServiceType::Prowlarr)) {
            $tools[] = resolve(SearchIndexersTool::class);
            $tools[] = resolve(ListIndexersTool::class);
        }

        if ($this->hasActiveConnection(ServiceType::Bazarr)) {
            $tools[] = resolve(InspectSubtitleTool::class);
            $tools[] = resolve(SearchSubtitlesTool::class);
            $tools[] = resolve(RequestSubtitleOperationTool::class);
        }

        if (! empty(config('services.tmdb.api_key'))) {
            $tools[] = resolve(TmdbGetTitleTool::class);
            $tools[] = resolve(TmdbGetSimilarTool::class);
            $tools[] = resolve(TmdbGetCreditsTool::class);
        }

        if (! empty(config('services.trakt.client_id'))) {
            $tools[] = resolve(TraktGetTrendingTool::class);
            $tools[] = resolve(TraktGetPopularTool::class);
            $tools[] = resolve(TraktGetListTool::class);
        }

        return $tools;
    }

    private function hasActiveConnection(ServiceType $serviceType): bool
    {
        return ServiceConnection::query()
            ->where('type', $serviceType)
            ->where('is_active', true)
            ->exists();
    }
}
