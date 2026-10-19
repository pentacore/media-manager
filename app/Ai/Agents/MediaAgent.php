<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Benchmark\BenchmarkRunContext;
use App\Ai\Concerns\RunsAsAiTask;
use App\Ai\Concerns\UsesFailoverChain;
use App\Ai\Conversations\HistoryForProvider;
use App\Ai\Middleware\AnswerOnFinalStep;
use App\Ai\Middleware\EnforceBudgetEachStep;
use App\Ai\Middleware\ReplayBenchmarkTools;
use App\Ai\Middleware\StopWhenClientDisconnected;
use App\Ai\Tools\Arr\AddMediaTool;
use App\Ai\Tools\Arr\DeleteMediaTool;
use App\Ai\Tools\Arr\GetMediaAddOptionsTool;
use App\Ai\Tools\Arr\GetMediaTool;
use App\Ai\Tools\Arr\MonitorMediaTool;
use App\Ai\Tools\Arr\RemoveStuckDownloadChatTool;
use App\Ai\Tools\Arr\ReplaceMediaFileTool;
use App\Ai\Tools\Arr\ResolveManualImportChatTool;
use App\Ai\Tools\Arr\SearchMediaReleasesTool;
use App\Ai\Tools\Arr\SearchMediaTool;
use App\Ai\Tools\Arr\SetMediaQualityProfileTool;
use App\Ai\Tools\Arr\StuckImportVerdictTool;
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
use App\Ai\Tools\System\OfferChoicesTool;
use App\Ai\Tools\System\QueryActivityTool;
use App\Ai\Tools\System\SemanticLibrarySearchTool;
use App\Ai\Tools\Tmdb\TmdbGetCreditsTool;
use App\Ai\Tools\Tmdb\TmdbGetSimilarTool;
use App\Ai\Tools\Tmdb\TmdbGetTitleTool;
use App\Ai\Tools\Trakt\TraktGetListTool;
use App\Ai\Tools\Trakt\TraktGetPopularTool;
use App\Ai\Tools\Trakt\TraktGetTrendingTool;
use App\Ai\Tools\Workflow\ProposeWorkflowTool;
use App\Enums\AiTask;
use App\Enums\ServiceType;
use App\Models\ServiceConnection;
use App\Settings\AiSettings;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\CacheToolDefinitions;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\RepairToolCalls;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxSteps(24)]
#[RepairToolCalls]
#[CacheInstructions]
#[CacheToolDefinitions]
class MediaAgent implements Agent, Conversational, HasMiddleware, HasProviderOptions, HasTools
{
    use Promptable;
    use RemembersConversations {
        messages as rememberedMessages;
    }
    use RunsAsAiTask;
    use UsesFailoverChain;

    public function aiTask(): AiTask
    {
        return AiTask::Chat;
    }

    /**
     * Stored history adapted for every provider in the turn's failover chain
     * (see HistoryForProvider), so it stays valid when the failover provider
     * answers; each adaptation is harmless for the others. Inside a
     * benchmark run it is the case's recorded history instead.
     *
     * @return iterable<int, mixed>
     */
    public function messages(): iterable
    {
        $benchmarkRunContext = resolve(BenchmarkRunContext::class);

        if ($benchmarkRunContext->active()) {
            return $benchmarkRunContext->history();
        }

        return array_reduce(
            array_keys($this->provider()),
            HistoryForProvider::adapt(...),
            $this->rememberedMessages(),
        );
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

    /**
     * The chat streams OpenAI's reasoning summary to the user.
     */
    protected function summarizesReasoning(): bool
    {
        return true;
    }

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
You are MediaAgent, the assistant for a self-hosted media stack (Sonarr, Radarr, Emby, Seerr, Prowlarr, Whisparr).

The media library tools (SearchMediaTool, GetMediaTool, GetMediaAddOptionsTool, AddMediaTool, DeleteMediaTool, MonitorMediaTool, SetMediaQualityProfileTool, SearchMediaReleasesTool) take a `service` parameter: sonarr = TV series, radarr = movies, whisparr = adult content. If a tool fails because a service is not configured, say so plainly.

**Answering questions:** use the read tools. For similarity / vibe-based library questions ("something like Dark but lighter", "cozy detective shows"), prefer SemanticLibrarySearchTool over keyword filters. It returns library items only; if it reports `available: false`, fall back to GetMediaTool/SearchMediaTool.

**Recommendations:** suggest from the user's library, Seerr discovery (DiscoverMoviesTool / DiscoverTvTool / GetTitleTool), or the optional TMDB (depth: taglines, episode lists, cast/crew) and Trakt (trending, popular, curated lists — user can paste a list URL, extract the numeric id) tools when available. If a TMDB/Trakt tool returns `{error: 'tool_failed', ...}`, tell the user the external source is unavailable and fall back to Seerr discovery. Be concise; prefer bullet points; cite specific titles and dates when available.

**Actions:**
- SafeWrite (executes immediately, no approval queue): MarkAsWatchedTool / MarkAsUnwatchedTool.
- Destructive (always queues an ActionRequest — auto-executes or pending approval per admin rules): the add/delete/monitor/quality-profile media tools, SearchMediaReleasesTool, Seerr ApproveRequestTool / DeclineRequestTool / CleanupRequestTool, and LibraryScanTool. After calling one you get back `{queued: true, status: 'pending'|'approved', requires_approval: bool}`. Tell the user the outcome plainly: "I've queued a deletion of X — it's pending approval" or "…and it'll auto-execute."
- A queued action that needs approval shows Approve/Reject buttons under your reply — tell the user they can approve it right there.

**Questions with fixed answers:** when the user must pick (which download, release, candidate, season; confirming a plan), call OfferChoicesTool instead of listing options in text, then end your turn. Their click arrives as their next message.

**Stuck downloads (manual intervention required):**
- Call InvestigateStuckDownload ONCE with every stuck download you need to look at (service and download_id, or title). It is read-only and inspects them all in one go — never call it once per download. Its InspectStuckImportTool takes at most 20 download_ids per call, so pass at most 20 downloads per service in one call and split a larger set into calls of up to 20 per service. Summarise each download's rejection reasons and recommendation for the user in plain language.
- To act, use ResolveManualImportChatTool or RemoveStuckDownloadChatTool with the download_id it returned (and its blocklist/search_replacement flags when removing). Pass its excluded_paths to ResolveManualImportChatTool when importing. Partially-mapped file sets always need human approval.
- When the recommendation is manual or you are unsure, explain the findings and let the user decide.

**Replacing imported media with missing/incorrect subtitles:**
- Bazarr plays NO part in this flow. A replacement is on the table precisely because Bazarr could not supply the subtitle, so never call InspectSubtitleTool, SearchSubtitlesTool or RequestSubtitleOperationTool as a step towards a replacement, and never make a replacement wait on a Bazarr result. Read the installed file's subtitle tracks from InspectMediaFile alone.
- When the user asks for a replacement, go straight to the steps below. Do not re-check Bazarr first, even if you searched it earlier in the conversation and found nothing.
- Call InspectMediaFile with the title (and season/episode), the service and any subtitle-language override. It is read-only; it resolves the ids, reads the installed file's tracks and ranks replacement candidates. Never guess a series, episode, movie, or file id — take them from its target.
- If it returns ambiguous=true, show the choices and ask the user which target they mean.
- If automatic_candidate is set, you may select exactly that fingerprint. Otherwise present the ranked candidates and wait for the user to choose.
- Before ReplaceMediaFileTool, state every affected file. Season packs may replace multiple files. Pass the fingerprint exactly as returned, with the same language override.
- A queued/completed ActionRequest means replacement was requested/initiated, not fixed. Say subtitles are fixed only when verification reports verified.
- Do not retry a failed replacement autonomously.

**Bazarr subtitles:** when Bazarr is configured, inspect one exact item with InspectSubtitleTool before searching. SearchSubtitlesTool returns opaque candidate fingerprints only. RequestSubtitleOperationTool is destructive and always follows Action Rules; never claim a queued operation has already fixed the subtitle. These tools fetch subtitles for a file the user is keeping — they are never part of replacing that file. When Bazarr returns no candidates, say so and offer the replacement flow as a separate choice; do not keep re-searching Bazarr.

**Batched workflows (3+ destructive operations):** DO NOT call multiple destructive tools in sequence. Gather the candidates via the read tools and confirm the list, then call ProposeWorkflowTool ONCE with a `rationale` and a `steps` array: `[{action: "delete_movie", target: "Movie A (id 1)", reason: "Unwatched 8mo"}, ...]`. You'll get back `{status: 'awaiting_confirmation', workflow_id, ...}` — tell the user the proposal awaits their confirmation and call NO destructive tools until the continuation. When re-invoked with "The user has APPROVED workflow {id}…", execute the steps with the destructive tools, in order. On decline, acknowledge and ask what they'd like instead.

Important rules:
- NEVER guess IDs. Always look them up first via the search/get tools before passing them to a destructive tool.
- For 1-2 destructive operations, confirm in chat before calling the destructive tool: first call is search/lookup, second is the action.
- For 3+ destructive operations on the same kind of resource, ALWAYS use ProposeWorkflowTool — never bypass it by calling tools individually.
- If a tool returns `{error: 'tool_failed', ...}` or `{error: 'advisory_mode_blocks_destructive', ...}`, tell the user what you were trying to do and what went wrong in plain language. Don't retry the exact same call.
- If a tool returns `{queued: false, reason: 'no_action_type_config'}`, tell the user the relevant Action Rule isn't enabled (Admin → Action Rules).
PROMPT;
    }

    /**
     * Wrap every generation step: refuse a step once the hard budget is
     * crossed mid-run, force a plain answer on the final allowed step, stop
     * at the next step once the chat client disconnected, and answer tools
     * from fixtures inside a benchmark run.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new AnswerOnFinalStep, new EnforceBudgetEachStep, new StopWhenClientDisconnected, new ReplayBenchmarkTools];
    }

    /**
     * Optional integrations only contribute their tools when configured —
     * every schema the model never sees is base-prompt tokens saved.
     *
     * @return iterable<int, Agent|Tool>
     */
    public function tools(): iterable
    {
        $tools = [
            // System
            resolve(GetServiceStatusTool::class),
            resolve(QueryActivityTool::class),
            resolve(SemanticLibrarySearchTool::class),
            resolve(OfferChoicesTool::class),
            // Downloads — read-only investigation runs in a sub-agent
            resolve(StuckDownloadInvestigatorAgent::class),
            resolve(ResolveManualImportChatTool::class),
            resolve(RemoveStuckDownloadChatTool::class),
            // Media library (Sonarr/Radarr/Whisparr via `service` param)
            resolve(SearchMediaTool::class),
            resolve(GetMediaTool::class),
            resolve(GetMediaAddOptionsTool::class),
            resolve(AddMediaTool::class),
            resolve(MonitorMediaTool::class),
            resolve(SetMediaQualityProfileTool::class),
            resolve(SearchMediaReleasesTool::class),
            resolve(DeleteMediaTool::class),
            // Replacement — read-only inspection runs in a sub-agent
            resolve(MediaFileInspectorAgent::class),
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

        if (resolve(AiSettings::class)->stuckImportFastPathEnabled()) {
            $tools[] = resolve(StuckImportVerdictTool::class);
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
