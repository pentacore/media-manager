<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Ai\Decision\InspectStuckImportTool;
use App\Ai\Tools\BaseTool;
use App\Models\ServiceConnection;
use App\Settings\AiSettings;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;

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

test('every tool returned by tools() is a usable SDK Tool', function (): void {
    $tools = iterator_to_array((new MediaAgent)->tools(), false);

    foreach ($tools as $tool) {
        // Most tools extend BaseTool; the context-free InspectStuckImportTool
        // implements the SDK Tool contract directly. Both are valid.
        expect($tool)->toBeInstanceOf(Tool::class);

        // Advisory-mode enforcement lives in BaseTool::handle(). Any tool that
        // bypasses BaseTool must be read-only by design and consciously
        // whitelisted here — a Destructive tool outside BaseTool would skip
        // the advisory gate entirely.
        if (! $tool instanceof BaseTool) {
            expect($tool::class)->toBe(InspectStuckImportTool::class);
        }
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

test('tool list includes the download queue/history/stuck-import tools', function (): void {
    $tools = collect(iterator_to_array((new MediaAgent)->tools(), false));

    $shortNames = $tools->map(fn ($t): string => class_basename($t))->all();

    expect($shortNames)->toContain('GetDownloadQueueTool');
    expect($shortNames)->toContain('GetDownloadHistoryTool');
    expect($shortNames)->toContain('InspectStuckImportTool');
    expect($shortNames)->toContain('ResolveManualImportChatTool');
    expect($shortNames)->toContain('RemoveStuckDownloadChatTool');
});

test('tool list includes the subtitle replacement tools', function (): void {
    $shortNames = collect(iterator_to_array((new MediaAgent)->tools(), false))
        ->map(fn ($t): string => class_basename($t))->all();

    expect($shortNames)->toContain('InspectMediaFileTool')
        ->toContain('FindReplacementCandidatesTool')
        ->toContain('ReplaceMediaFileTool');
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

test('tool list has the 31 core tools when no optional integration is configured', function (): void {
    $tools = collect(iterator_to_array((new MediaAgent)->tools(), false));

    expect($tools->count())->toBe(31);
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

test('model() reads from AiSettings', function (): void {
    resolve(AiSettings::class)->setModel('gpt-4o-mini');

    expect((new MediaAgent)->model())->toBe('gpt-4o-mini');
});

test('instructions cover the behavioral guidance the schemas cannot express', function (): void {
    $instructions = (string) (new MediaAgent)->instructions();

    // Tool routing that lives in the prompt (not in any schema)
    expect($instructions)->toContain('SemanticLibrarySearchTool')
        ->toContain('ProposeWorkflowTool')
        ->toContain('InspectStuckImportTool')
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
    expect($instructions)->toContain('InspectMediaFileTool')
        ->toContain('FindReplacementCandidatesTool')
        ->toContain('ReplaceMediaFileTool')
        ->toContain('automatic_candidate')
        ->toContain('verified');

    expect($instructions)->toContain('InspectSubtitleTool')
        ->toContain('SearchSubtitlesTool')
        ->toContain('RequestSubtitleOperationTool');
});
