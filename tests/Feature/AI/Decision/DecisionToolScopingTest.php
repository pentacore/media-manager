<?php

declare(strict_types=1);

use App\Ai\Agents\DecisionAgent;
use App\Ai\Decision\DecisionRunContext;
use App\Ai\Routing\DecisionActionKind;
use App\Ai\Tools\Arr\SearchMediaTool;
use App\Ai\Tools\Decision\InspectStuckImportTool;
use App\Ai\Tools\Decision\ProposeActionTool;
use App\Ai\Tools\Emby\NowPlayingTool;
use App\Ai\Tools\Seerr\ListPendingRequestsTool;
use App\Enums\ClassificationGate;
use App\Enums\ClassificationVerdict;
use App\Jobs\RunDecisionAgent;
use App\Models\ActionRequest;
use App\Models\ClassificationOutcome;
use App\Settings\AiSettings;
use App\Settings\DecisionAgentSettings;
use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
    resolve(DecisionAgentSettings::class)->setEnabled(true);
    resolve(AiSettings::class)->setDecisionToolScopingEnabled(true);
});

function runScopedJob(string $eventType = 'Grab'): void
{
    app()->call([new RunDecisionAgent(null, 'sonarr', $eventType, ['series' => ['id' => random_int(1, 99999)], 'eventType' => $eventType, 'downloadId' => 'dl-1']), 'handle']);
}

/**
 * Fake the DecisionAgent and capture what a prompted agent would carry,
 * while the run context is still bound. The context is unbound again once
 * the job's try/finally runs, so tools()/instructions() must be read from
 * inside the fake's response closure, not from a later assertion.
 *
 * @return object{tools: list<class-string>, instructions: string}
 */
function captureDecisionAgentRun(): object
{
    $captured = (object) ['tools' => [], 'instructions' => ''];

    DecisionAgent::fake(function () use ($captured): string {
        $agent = new DecisionAgent;

        $captured->tools = collect(iterator_to_array($agent->tools()))
            ->flatMap(fn (object $tool): array => $tool instanceof ToolSearch ? $tool->tools : [$tool])
            ->map(fn (object $tool): string => $tool::class)
            ->values()
            ->all();
        $captured->instructions = (string) $agent->instructions();

        return 'ok';
    });

    return $captured;
}

function kindAnswer(string $choice, float $probability): ChoiceAnswer
{
    return new ChoiceAnswer($choice, [$choice => $probability]);
}

test('a confident library-change kind loads only the core and library tools', function (): void {
    $captured = captureDecisionAgentRun();
    Classification::fake([['action_kind' => kindAnswer('library_change', 0.8)]]);

    runScopedJob();

    expect($captured->tools)
        ->toContain(SearchMediaTool::class)
        ->toContain(ProposeActionTool::class)
        ->not->toContain(NowPlayingTool::class)
        ->not->toContain(ListPendingRequestsTool::class)
        ->not->toContain(InspectStuckImportTool::class);
});

test('a scoped run leaves the stuck-import section out of the instructions', function (): void {
    $captured = captureDecisionAgentRun();
    Classification::fake([['action_kind' => kindAnswer('seerr_request', 0.9)]]);

    runScopedJob();

    expect($captured->instructions)->not->toContain('STUCK IMPORTS');
});

test('a kind below the scope threshold keeps every tool', function (): void {
    $captured = captureDecisionAgentRun();
    Classification::fake([['action_kind' => kindAnswer('media_server', 0.49)]]);

    runScopedJob();

    expect($captured->tools)->toContain(InspectStuckImportTool::class);
    expect($captured->instructions)->toContain('STUCK IMPORTS');
    expect(ClassificationOutcome::sole()->verdict)->toBe(ClassificationVerdict::Unscoped);
});

test('a kind exactly at the scope threshold scopes the run', function (): void {
    DecisionAgent::fake(['ok']);
    Classification::fake([['action_kind' => kindAnswer('media_server', RunDecisionAgent::SCOPE_AT)]]);

    runScopedJob();

    expect(ClassificationOutcome::sole())->verdict->toBe(ClassificationVerdict::Scoped)->predicted->toBe('media_server');
});

test('the other kind keeps every tool', function (): void {
    $captured = captureDecisionAgentRun();
    Classification::fake([['action_kind' => kindAnswer('other', 0.95)]]);

    runScopedJob();

    expect($captured->tools)->toContain(NowPlayingTool::class);
});

test('a classifier failure keeps every tool', function (): void {
    $captured = captureDecisionAgentRun();
    Classification::fake(fn () => throw new RuntimeException('down'));

    runScopedJob();

    expect($captured->tools)->toContain(NowPlayingTool::class);
});

test('stuck-import events are never scoped or classified', function (): void {
    $captured = captureDecisionAgentRun();
    Classification::fake([]);

    runScopedJob('ManualInteractionRequired');

    Classification::assertNothingClassified();
    expect($captured->tools)->toContain(InspectStuckImportTool::class);
});

test('the gate and the kind are asked in one classification call', function (): void {
    resolve(AiSettings::class)->setDecisionGateEnabled(true);
    DecisionAgent::fake(['ok']);
    Classification::fake([[
        'decision' => new BooleanAnswer(0.9),
        'action_kind' => kindAnswer('library_change', 0.8),
    ]]);

    runScopedJob();

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->asks('decision') && $prompt->asks('action_kind'));
    expect(ClassificationOutcome::query()->pluck('gate')->all())->toEqualCanonicalizing([ClassificationGate::DecisionGate, ClassificationGate::ActionKind]);
});

test('the kind outcome is positive when every queued action belongs to it', function (): void {
    Classification::fake([['action_kind' => kindAnswer('library_change', 0.8)]]);
    DecisionAgent::fake(function (): string {
        resolve(DecisionRunContext::class)->recordQueued(ActionRequest::factory()->create(['type' => 'monitor_series'])->id, true);

        return 'Proposed.';
    });

    runScopedJob();

    expect(ClassificationOutcome::sole()->outcome_positive)->toBeTrue();
});

test('the kind outcome is negative when a queued action belongs to another kind', function (): void {
    Classification::fake([['action_kind' => kindAnswer('library_change', 0.8)]]);
    DecisionAgent::fake(function (): string {
        resolve(DecisionRunContext::class)->recordQueued(ActionRequest::factory()->create(['type' => 'emby_library_scan'])->id, true);

        return 'Proposed.';
    });

    runScopedJob();

    expect(ClassificationOutcome::sole()->outcome_positive)->toBeFalse();
});

test('every allowed proposal type maps to a kind', function (): void {
    foreach (ProposeActionTool::ALLOWED_TYPES as $type) {
        expect(DecisionActionKind::forActionType($type))->not->toBeNull();
    }
});
