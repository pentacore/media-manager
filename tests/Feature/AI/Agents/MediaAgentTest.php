<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Ai\Agents\MediaFileInspectorAgent;
use App\Ai\Agents\StuckDownloadInvestigatorAgent;
use App\Ai\Routing\ChatToolRunContext;
use App\Ai\Routing\RoutedToolGroups;
use App\Ai\Routing\ToolGroupSource;
use App\Ai\Tools\BaseTool;
use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Models\AiTaskModel;
use App\Models\ServiceConnection;
use App\Settings\AiSettings;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;

beforeEach(function (): void {
    config()->set('services.tmdb.api_key');
    config()->set('services.trakt.client_id');
});

test('MediaAgent is an Agent + Conversational + HasTools', function (): void {
    $agent = new MediaAgent;

    expect($agent)->toBeInstanceOf(Agent::class);
    expect($agent)->toBeInstanceOf(Conversational::class);
    expect($agent)->toBeInstanceOf(HasTools::class);
});

test('MediaAgent takes its prompt timeout from AiSettings', function (): void {
    // The SDK reads this method in Promptable::getTimeout() for both prompt()
    // and stream(), in preference to its own default.
    resolve(AiSettings::class)->setChatTimeout(300);

    expect((new MediaAgent)->timeout())->toBe(300);
});

test('every tool returned by tools() is a BaseTool or a read-only sub-agent', function (): void {
    $tools = iterator_to_array((new MediaAgent)->tools(), false);

    foreach ($tools as $tool) {
        // Advisory-mode enforcement lives in BaseTool::handle(). Anything
        // outside BaseTool must be read-only by design and consciously
        // whitelisted here — a Destructive tool outside BaseTool would skip
        // the advisory gate entirely. The investigation sub-agents are
        // read-only and the SDK wraps them as tools by their name().
        if ($tool instanceof Agent) {
            expect($tool)->toBeInstanceOf(CanActAsTool::class)
                ->and($tool::class)->toBeIn([StuckDownloadInvestigatorAgent::class, MediaFileInspectorAgent::class]);

            continue;
        }

        expect($tool)->toBeInstanceOf(Tool::class)
            ->toBeInstanceOf(BaseTool::class);
    }
});

test('tool list includes the core tool families', function (): void {
    $tools = collect(iterator_to_array((new MediaAgent)->tools(), false));

    $shortNames = $tools->map(fn ($t): string => class_basename($t))->all();

    expect($shortNames)->toContain('GetServiceStatusTool');
    expect($shortNames)->toContain('QueryActivityTool');
    expect($shortNames)->toContain('SemanticLibrarySearchTool');
    expect($shortNames)->toContain('SearchMediaTool');
    expect($shortNames)->toContain('GetMediaTool');
    expect($shortNames)->toContain('AddMediaTool');
    expect($shortNames)->toContain('MonitorMediaTool');
    expect($shortNames)->toContain('SetMediaQualityProfileTool');
    expect($shortNames)->toContain('DeleteMediaTool');
    expect($shortNames)->toContain('NowPlayingTool');
    expect($shortNames)->toContain('MarkAsWatchedTool');
    expect($shortNames)->toContain('MarkAsUnwatchedTool');
    expect($shortNames)->toContain('SearchCatalogTool');
    expect($shortNames)->toContain('ApproveRequestTool');
    expect($shortNames)->toContain('DeclineRequestTool');
    expect($shortNames)->toContain('ProposeWorkflowTool');
});

test('tool list includes the stuck-download investigator and acting tools', function (): void {
    $tools = collect(iterator_to_array((new MediaAgent)->tools(), false));

    $shortNames = $tools->map(fn ($t): string => class_basename($t))->all();

    expect($shortNames)->toContain('StuckDownloadInvestigatorAgent');
    expect($shortNames)->toContain('ResolveManualImportChatTool');
    expect($shortNames)->toContain('RemoveStuckDownloadChatTool');
});

test('tool list includes the subtitle replacement tools', function (): void {
    $shortNames = collect(iterator_to_array((new MediaAgent)->tools(), false))
        ->map(fn ($t): string => class_basename($t))->all();

    expect($shortNames)->toContain('MediaFileInspectorAgent')
        ->toContain('ReplaceMediaFileTool')
        ->not->toContain('InspectMediaFileTool')
        ->not->toContain('FindReplacementCandidatesTool');
});

test('the instructions tell the agent about choice buttons and inline approval', function (): void {
    $instructions = (string) (new MediaAgent)->instructions();

    expect($instructions)->toContain('OfferChoicesTool')
        ->and($instructions)->toContain('Approve/Reject buttons');
});

/**
 * One bold-headed section of the instructions, up to the next bold heading.
 */
function mediaAgentPromptSection(string $heading): string
{
    $instructions = (string) (new MediaAgent)->instructions();

    expect($instructions)->toContain($heading);

    [, $section] = explode($heading, $instructions, 2);
    [$section] = preg_split('/\n\n(?=\*\*|Important rules:)/', $section, 2);

    return $section;
}

test('the stuck-download instructions pin the single batched investigation and the action rails', function (): void {
    $section = mediaAgentPromptSection('**Stuck downloads (manual intervention required):**');

    expect($section)->toContain('Call InvestigateStuckDownload ONCE')
        ->toContain('never call it once per download')
        ->toContain('at most 20 download_ids per call')
        ->toContain('ResolveManualImportChatTool or RemoveStuckDownloadChatTool')
        ->toContain('blocklist/search_replacement flags')
        ->toContain('Pass its excluded_paths to ResolveManualImportChatTool')
        ->toContain('Partially-mapped file sets always need human approval')
        ->toContain('let the user decide');
});

test('the fixed-answer instructions send picks through OfferChoicesTool and end the turn', function (): void {
    $section = mediaAgentPromptSection('**Questions with fixed answers:**');

    expect($section)->toContain('call OfferChoicesTool instead of listing options in text')
        ->toContain('end your turn')
        ->toContain('Their click arrives as their next message');
});

test('the batched-workflow instructions route three or more destructive operations through one proposal', function (): void {
    $section = mediaAgentPromptSection('**Batched workflows (3+ destructive operations):**');

    expect($section)->toContain('DO NOT call multiple destructive tools in sequence')
        ->toContain('call ProposeWorkflowTool ONCE')
        ->toContain('awaiting_confirmation')
        ->toContain('call NO destructive tools until the continuation')
        ->toContain('The user has APPROVED workflow');
});

test('the important rules forbid guessing ids and bypassing the workflow proposal', function (): void {
    $instructions = (string) (new MediaAgent)->instructions();

    [, $rules] = explode('Important rules:', $instructions, 2);

    expect($rules)->toContain('NEVER guess IDs')
        ->toContain('never bypass it by calling tools individually')
        ->toContain('advisory_mode_blocks_destructive')
        ->toContain('no_action_type_config');
});

test('routed turns append the tool-group instructions and full-toolset turns do not', function (): void {
    expect((string) (new MediaAgent)->instructions())->not->toContain('**Tools you may not have loaded:**');

    resolve(ChatToolRunContext::class)->routed = new RoutedToolGroups(['playback' => ToolGroupSource::Picked]);
    $routed = (string) (new MediaAgent)->instructions();

    expect($routed)->toContain('**Tools you may not have loaded:**')
        ->toContain('call FindToolsTool with the need before answering')
        ->toContain('call RequestToolsTool with that group')
        ->toContain('Load / No thanks buttons');
});

test('the replacement instructions keep Bazarr out of the replacement flow', function (): void {
    $instructions = (string) (new MediaAgent)->instructions();

    [, $replacementSection] = explode('**Replacing imported media with missing/incorrect subtitles:**', $instructions, 2);
    [$replacementSection] = explode('**Bazarr subtitles:**', $replacementSection, 2);

    // The replacement flow must not route through Bazarr: it exists precisely
    // because Bazarr already failed to supply the subtitle. Without this the
    // agent re-checks Bazarr, finds nothing, and never reaches the arr grab.
    expect($replacementSection)->toContain('Bazarr plays NO part in this flow')
        ->and($replacementSection)->toContain('InspectSubtitleTool')
        ->and($replacementSection)->toContain('SearchSubtitlesTool')
        ->and($replacementSection)->toContain('RequestSubtitleOperationTool')
        ->and($replacementSection)->toContain('Do not re-check Bazarr first');
});

test('tool list has the 33 core tools when no optional integration is configured', function (): void {
    $tools = collect(iterator_to_array((new MediaAgent)->tools(), false));

    expect($tools->count())->toBe(33);
});

test('Prowlarr tools appear only with an active Prowlarr connection', function (): void {
    $shortNames = fn (): array => collect(iterator_to_array((new MediaAgent)->tools(), false))
        ->map(fn ($t): string => class_basename($t))->all();

    expect($shortNames())->not->toContain('SearchIndexersTool');

    $connection = ServiceConnection::factory()->prowlarr()->create(['is_active' => true]);

    expect($shortNames())->toContain('SearchIndexersTool')
        ->toContain('ListIndexersTool');

    $connection->update(['is_active' => false]);

    expect($shortNames())->not->toContain('SearchIndexersTool');
});

test('Bazarr subtitle tools appear only with an active Bazarr connection', function (): void {
    $shortNames = fn (): array => collect(iterator_to_array((new MediaAgent)->tools(), false))
        ->map(fn ($tool): string => class_basename($tool))->all();

    expect($shortNames())->not->toContain('InspectSubtitleTool');

    $connection = ServiceConnection::factory()->bazarr()->create(['is_active' => true]);

    expect($shortNames())->toContain('InspectSubtitleTool')
        ->toContain('SearchSubtitlesTool')
        ->toContain('RequestSubtitleOperationTool');

    $connection->update(['is_active' => false]);

    expect($shortNames())->not->toContain('InspectSubtitleTool');
});

test('TMDB tools appear only when an API key is configured', function (): void {
    $shortNames = fn (): array => collect(iterator_to_array((new MediaAgent)->tools(), false))
        ->map(fn ($t): string => class_basename($t))->all();

    expect($shortNames())->not->toContain('TmdbGetTitleTool');

    config()->set('services.tmdb.api_key', 'key');

    expect($shortNames())->toContain('TmdbGetTitleTool')
        ->toContain('TmdbGetSimilarTool')
        ->toContain('TmdbGetCreditsTool');
});

test('Trakt tools appear only when a client id is configured', function (): void {
    $shortNames = fn (): array => collect(iterator_to_array((new MediaAgent)->tools(), false))
        ->map(fn ($t): string => class_basename($t))->all();

    expect($shortNames())->not->toContain('TraktGetTrendingTool');

    config()->set('services.trakt.client_id', 'id');

    expect($shortNames())->toContain('TraktGetTrendingTool')
        ->toContain('TraktGetPopularTool')
        ->toContain('TraktGetListTool');
});

test('model() reads the chat task selection', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-4o-mini')->create();

    expect((new MediaAgent)->model())->toBe('gpt-4o-mini');
});

test('instructions cover the behavioral guidance the schemas cannot express', function (): void {
    $instructions = (string) (new MediaAgent)->instructions();

    // Tool routing that lives in the prompt (not in any schema)
    expect($instructions)->toContain('SemanticLibrarySearchTool')
        ->toContain('ProposeWorkflowTool')
        ->toContain('InvestigateStuckDownload ONCE')
        ->toContain('excluded_paths')
        ->toContain('ResolveManualImportChatTool')
        ->toContain('RemoveStuckDownloadChatTool')
        ->toContain('SearchMediaTool')
        ->toContain('GetMediaTool');

    // Behavioral rules
    expect($instructions)->toContain('NEVER guess IDs')
        ->toContain('advisory_mode_blocks_destructive')
        ->toContain('no_action_type_config')
        ->toContain('awaiting_confirmation');

    // Subtitle replacement workflow guidance
    expect($instructions)->toContain('InspectMediaFile')
        ->not->toContain('InspectMediaFileTool')
        ->not->toContain('FindReplacementCandidatesTool')
        ->toContain('ReplaceMediaFileTool')
        ->toContain('automatic_candidate')
        ->toContain('verified');

    expect($instructions)->toContain('InspectSubtitleTool')
        ->toContain('SearchSubtitlesTool')
        ->toContain('RequestSubtitleOperationTool');
});

test('MediaAgent asks OpenAI for a reasoning summary at the configured effort', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openai', 'gpt-5')->reasoning(AiReasoningLevel::Medium)->create();

    expect((new MediaAgent)->providerOptions(Lab::OpenAI))
        ->toBe(['reasoning' => ['effort' => 'medium', 'summary' => 'auto']]);
});
