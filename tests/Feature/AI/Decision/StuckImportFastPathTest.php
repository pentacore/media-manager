<?php

declare(strict_types=1);

use App\Ai\Agents\DecisionAgent;
use App\Enums\AgentDecisionStatus;
use App\Enums\ClassificationGate;
use App\Enums\ClassificationVerdict;
use App\Jobs\RunDecisionAgent;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\AgentDecision;
use App\Models\ClassificationOutcome;
use App\Models\ServiceConnection;
use App\Settings\AiSettings;
use App\Settings\DecisionAgentSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    config()->set('mediamanager.ai.enabled', true);
    resolve(DecisionAgentSettings::class)->setEnabled(true);
    resolve(DecisionAgentSettings::class)->setAllowManualImport(true);
    resolve(AiSettings::class)->setStuckImportFastPathEnabled(true);
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    ActionTypeConfig::factory()->create(['type' => 'resolve_manual_import', 'requires_approval' => false, 'is_enabled' => true]);
    ActionTypeConfig::factory()->create(['type' => 'remove_stuck_download', 'requires_approval' => true, 'is_enabled' => true]);
    DecisionAgent::fake(['Agent summary.']);
});

/**
 * The beforeEach Http::fake() would otherwise win over a later per-test fake
 * for the same URL (first match wins), so tests that need specific candidates
 * call this helper instead — the same pattern ResolveManualImportToolTest
 * uses.
 *
 * @param  array<int, array<string, mixed>>  $candidates
 */
function fakeStuckImportApis(array $candidates): void
{
    Http::fake([
        'sonarr.local:8989/api/v3/manualimport*' => Http::response($candidates),
        'sonarr.local:8989/api/v3/queue*' => Http::response(['records' => [
            ['downloadId' => 'dl-1', 'title' => 'Show.S01E01.1080p', 'series' => ['title' => 'Show']],
        ]]),
    ]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function stuckImportCandidates(): array
{
    return [[
        'path' => '/dl/show.s01e01.mkv',
        'quality' => ['quality' => ['name' => 'WEBDL-1080p']],
        'series' => ['id' => 5],
        'episodes' => [['id' => 11]],
        'rejections' => [],
    ]];
}

/**
 * @param  array<string, mixed>  $payload
 */
function runStuckImportJob(array $payload = ['eventType' => 'ManualInteractionRequired', 'downloadId' => 'dl-1'], string $service = 'sonarr'): void
{
    app()->call([new RunDecisionAgent(null, $service, 'ManualInteractionRequired', $payload), 'handle']);
}

/**
 * @return array<string, mixed>
 */
function fastPathAnswers(string $choice, float $probability, float $blocklist = 0.1, float $search = 0.1): array
{
    return [
        'choice' => new ChoiceAnswer($choice, [$choice => $probability]),
        'blocklist' => new BooleanAnswer($blocklist),
        'search_replacement' => new BooleanAnswer($search),
    ];
}

test('a confident import queues the import without running the agent', function (): void {
    fakeStuckImportApis(stuckImportCandidates());
    Classification::fake([fastPathAnswers('import', 0.95)]);

    runStuckImportJob();

    DecisionAgent::assertNeverPrompted();
    expect(ActionRequest::sole())->type->toBe('resolve_manual_import')->requires_approval->toBeFalse()
        ->and(AgentDecision::sole())->status->toBe(AgentDecisionStatus::ResolvedByClassifier)->summary->toContain('95%')
        ->and(ClassificationOutcome::sole())->gate->toBe(ClassificationGate::StuckImport)->verdict->toBe(ClassificationVerdict::ResolvedByClassifier)->predicted->toBe('import');
});

test('a confident removal is always queued for approval with the flags it earned', function (): void {
    fakeStuckImportApis(stuckImportCandidates());
    Classification::fake([fastPathAnswers('remove', 0.9, blocklist: 0.85, search: 0.5)]);

    runStuckImportJob();

    $actionRequest = ActionRequest::sole();

    expect($actionRequest->type)->toBe('remove_stuck_download')
        ->and($actionRequest->requires_approval)->toBeTrue()
        ->and($actionRequest->payload['blocklist'])->toBeTrue()
        ->and($actionRequest->payload['search_replacement'])->toBeFalse();
    DecisionAgent::assertNeverPrompted();
});

test('a confident manual call records a needs-human decision and queues nothing', function (): void {
    fakeStuckImportApis(stuckImportCandidates());
    Classification::fake([fastPathAnswers('manual', 0.9)]);

    runStuckImportJob();

    DecisionAgent::assertNeverPrompted();
    expect(ActionRequest::count())->toBe(0)
        ->and(AgentDecision::sole())->status->toBe(AgentDecisionStatus::ResolvedByClassifier)->summary->toContain('needs a human');
});

test('an unconfident call falls back to the agent and records the prediction', function (): void {
    fakeStuckImportApis(stuckImportCandidates());
    Classification::fake([fastPathAnswers('import', 0.6)]);

    runStuckImportJob();

    DecisionAgent::assertPrompted(fn (): bool => true);
    expect(ClassificationOutcome::sole())->verdict->toBe(ClassificationVerdict::Fallback)
        ->and(ClassificationOutcome::sole()->outcome_positive)->toBeFalse()
        ->and(ClassificationOutcome::sole()->outcome_detail)->toBe('actual: manual');
});

test('a classifier without an answer falls back to the agent with nothing resolved', function (): void {
    fakeStuckImportApis(stuckImportCandidates());
    Classification::fake(fn () => throw new RuntimeException('down'));

    runStuckImportJob();

    DecisionAgent::assertPrompted(fn (): bool => true);
    expect(AgentDecision::sole()->status)->not->toBe(AgentDecisionStatus::ResolvedByClassifier)
        ->and(ClassificationOutcome::count())->toBe(0);
});

test('a payload without a download id runs the agent as today', function (): void {
    Classification::fake([]);

    runStuckImportJob(['eventType' => 'ManualInteractionRequired']);

    DecisionAgent::assertPrompted(fn (): bool => true);
    Classification::assertNothingClassified();
});

test('a disabled manual-import capability records no action without classifying', function (): void {
    resolve(DecisionAgentSettings::class)->setAllowManualImport(false);
    Classification::fake([]);

    runStuckImportJob();

    DecisionAgent::assertNeverPrompted();
    Classification::assertNothingClassified();
    expect(AgentDecision::sole())->status->toBe(AgentDecisionStatus::NoAction)->summary->toContain('disabled');
});

test('the fast path stays off until enabled', function (): void {
    resolve(AiSettings::class)->setStuckImportFastPathEnabled(false);
    Classification::fake([]);

    runStuckImportJob();

    DecisionAgent::assertPrompted(fn (): bool => true);
    Classification::assertNothingClassified();
});

test('a fully unmapped download is recorded as needing a human', function (): void {
    fakeStuckImportApis([[
        'path' => '/dl/unknown.mkv', 'quality' => ['quality' => ['name' => 'WEBDL-1080p']], 'series' => [], 'episodes' => [], 'rejections' => [['reason' => 'Unknown Series']],
    ]]);
    Classification::fake([fastPathAnswers('import', 0.95)]);

    runStuckImportJob();

    DecisionAgent::assertNeverPrompted();
    expect(ActionRequest::count())->toBe(0)
        ->and(AgentDecision::sole())->status->toBe(AgentDecisionStatus::ResolvedByClassifier)->summary->toContain('needs a human');
});
