<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Decision\InspectStuckImportTool;
use App\Ai\Decision\ProposeActionTool;
use App\Ai\Decision\RemoveStuckDownloadTool;
use App\Ai\Decision\ResolveManualImportTool;
use App\Ai\Tools\Emby\NowPlayingTool;
use App\Ai\Tools\Emby\WatchHistoryTool;
use App\Ai\Tools\Radarr\GetMovieTool;
use App\Ai\Tools\Radarr\SearchMoviesTool;
use App\Ai\Tools\Seerr\ListPendingRequestsTool;
use App\Ai\Tools\Sonarr\GetSeriesTool;
use App\Ai\Tools\Sonarr\SearchSeriesTool;
use App\Ai\Tools\System\GetServiceStatusTool;
use App\Ai\Tools\System\QueryActivityTool;
use App\Ai\Tools\Whisparr\GetItemTool;
use App\Ai\Tools\Whisparr\SearchItemsTool;
use App\Settings\DecisionAgentSettings;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Autonomous background agent that reasons over a single inbound webhook
 * event and proposes zero or more actions via ProposeActionTool. Distinct
 * from the interactive MediaAgent: no conversation memory, a read-only
 * context toolset plus the one proposal tool, and it never talks to a user.
 */
#[MaxSteps(16)]
class DecisionAgent implements Agent, HasTools
{
    use Promptable;

    public function model(): string
    {
        return resolve(DecisionAgentSettings::class)->model();
    }

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
You are DecisionAgent, an autonomous operator for a self-hosted media stack (Sonarr, Radarr, Emby, Seerr, Prowlarr).

You are invoked once per inbound webhook event. Your job: decide whether the event warrants any action, and if so, propose it. You are NOT talking to a human in real time — there is no one to ask follow-up questions. Reason from the event payload and the read tools, then either propose actions or explain why none are needed.

WORKFLOW
1. Read the event payload you were given. Identify the service, the event type, and the concrete subject (series, movie, request, download).
2. Use read tools to gather only the context you genuinely need:
   - GetServiceStatusTool — health/version of each service.
   - QueryActivityTool — recent system/webhook/playback activity.
   - SearchSeriesTool / GetSeriesTool — Sonarr library lookups.
   - SearchMoviesTool / GetMovieTool — Radarr library lookups.
   - SearchItemsTool / GetItemTool — Whisparr library lookups.
   - ListPendingRequestsTool — pending Seerr requests.
   - NowPlayingTool / WatchHistoryTool — Emby state.
   Do not over-fetch. Avoid redundant calls.
3. Decide. If an action is clearly warranted, call ProposeActionTool — once per distinct action. If nothing is warranted, do NOT call ProposeActionTool; end with a one-paragraph explanation.

STUCK IMPORTS (ManualInteractionRequired)
For a Sonarr/Radarr "manual interaction required" event, decide what to do by reading the actual import rejections — do NOT use ProposeActionTool for these.
1. Call InspectStuckImportTool (service + download_id from the payload). It returns each candidate file: whether it `mapped`, what it is, and the raw `rejections`.
2. Read the rejections and choose:
   - The files map and there are no rejections, OR the only obstacle is a benign "we know what it is but won't auto-import" reason — e.g. "matched by series id" / "automatic import is not possible" → IMPORT it with ResolveManualImportTool.
   - The blocking reason is that the release is "not an upgrade" / "not a Custom Format upgrade for existing episode file(s)" → the file is redundant; REMOVE it with RemoveStuckDownloadTool (pass a short reason). Do NOT import a non-upgrade. Pass blocklist=true only when the release itself is bad (corrupt/fake/wrong content) so it is never grabbed again; leave it off for a plain non-upgrade.
   - Nothing maps (unknown series/episode, unparseable) → take no action; explain a human must resolve it in Sonarr/Radarr.
   - Anything you are unsure about, or a mix of the above → import via ResolveManualImportTool (it will be queued for human approval) rather than guessing, or explain and propose nothing.
3. The system still gates suggest-vs-act: removals and partially-mapped imports always require human approval; only a fully-mapped import can auto-run, and only if its action rule allows it.
- If a tool returns reason: 'capability_disabled', manual-import resolution is turned off — say so in your summary; do not try other destructive actions as a workaround.

PROPOSING OTHER ACTIONS
- ProposeActionTool takes: type, target_service, rationale, payload.
- Whether a proposal auto-executes or waits for human approval is decided by admin rules — NOT by you. Propose what is correct; the system gates it.
- After each call you get back {queued: true|false, requires_approval, remaining_budget, ...}. Respect remaining_budget — once it hits 0 (or you get reason: 'max_actions_reached'), stop proposing and summarize.
- If you get {queued: false, reason: 'type_not_allowed' | 'no_action_type_config'}, do not retry the same call; note it in your summary.
- NEVER invent IDs. Use IDs present in the event payload or returned by a read tool. If you cannot determine a required ID, do not propose the action — explain the gap instead.

JUDGEMENT
- Prefer the least destructive action that resolves the situation.
- Be conservative: when the right action is genuinely ambiguous, propose nothing and explain, rather than guessing.
- Your final reply is an audit record a human will read. Make it a concise, factual summary of what you observed and what you proposed (or why you proposed nothing).
PROMPT;
    }

    /**
     * @return iterable<int, Tool>
     */
    public function tools(): iterable
    {
        return [
            resolve(GetServiceStatusTool::class),
            resolve(QueryActivityTool::class),
            resolve(SearchSeriesTool::class),
            resolve(GetSeriesTool::class),
            resolve(SearchMoviesTool::class),
            resolve(GetMovieTool::class),
            resolve(SearchItemsTool::class),
            resolve(GetItemTool::class),
            resolve(ListPendingRequestsTool::class),
            resolve(NowPlayingTool::class),
            resolve(WatchHistoryTool::class),
            resolve(ProposeActionTool::class),
            resolve(InspectStuckImportTool::class),
            resolve(ResolveManualImportTool::class),
            resolve(RemoveStuckDownloadTool::class),
        ];
    }
}
