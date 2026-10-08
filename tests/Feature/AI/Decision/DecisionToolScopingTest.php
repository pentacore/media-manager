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
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\ClassificationPrompt;
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
 * @return list<class-string>
 */
function scopedToolClasses(AgentPrompt $agentPrompt): array
{
    return array_values(array_map(static fn (object $tool): string => $tool::class, iterator_to_array($agentPrompt->agent->tools())));
}

function kindAnswer(string $choice, float $probability): ChoiceAnswer
{
    return new ChoiceAnswer($choice, [$choice => $probability]);
}

test('a confident library-change kind loads only the core and library tools', function (): void {
    DecisionAgent::fake(['ok']);
    Classification::fake([['action_kind' => kindAnswer('library_change', 0.8)]]);

    runScopedJob();

    DecisionAgent::assertPrompted(function (AgentPrompt $agentPrompt): bool {
        $classes = scopedToolClasses($agentPrompt);

        return in_array(SearchMediaTool::class, $classes, true)
            && in_array(ProposeActionTool::class, $classes, true)
            && ! in_array(NowPlayingTool::class, $classes, true)
            && ! in_array(ListPendingRequestsTool::class, $classes, true)
            && ! in_array(InspectStuckImportTool::class, $classes, true);
    });
});

test('a scoped run leaves the stuck-import section out of the instructions', function (): void {
    DecisionAgent::fake(['ok']);
    Classification::fake([['action_kind' => kindAnswer('seerr_request', 0.9)]]);

    runScopedJob();

    DecisionAgent::assertPrompted(fn (AgentPrompt $agentPrompt): bool => ! str_contains((string) $agentPrompt->agent->instructions(), 'STUCK IMPORTS'));
});

test('a kind below the scope threshold keeps every tool', function (): void {
    DecisionAgent::fake(['ok']);
    Classification::fake([['action_kind' => kindAnswer('media_server', 0.49)]]);

    runScopedJob();

    DecisionAgent::assertPrompted(fn (AgentPrompt $agentPrompt): bool => in_array(InspectStuckImportTool::class, scopedToolClasses($agentPrompt), true)
        && str_contains((string) $agentPrompt->agent->instructions(), 'STUCK IMPORTS'));
    expect(ClassificationOutcome::sole()->verdict)->toBe(ClassificationVerdict::Unscoped);
});

test('a kind exactly at the scope threshold scopes the run', function (): void {
    DecisionAgent::fake(['ok']);
    Classification::fake([['action_kind' => kindAnswer('media_server', RunDecisionAgent::SCOPE_AT)]]);

    runScopedJob();

    expect(ClassificationOutcome::sole())->verdict->toBe(ClassificationVerdict::Scoped)->predicted->toBe('media_server');
});

test('the other kind keeps every tool', function (): void {
    DecisionAgent::fake(['ok']);
    Classification::fake([['action_kind' => kindAnswer('other', 0.95)]]);

    runScopedJob();

    DecisionAgent::assertPrompted(fn (AgentPrompt $agentPrompt): bool => in_array(NowPlayingTool::class, scopedToolClasses($agentPrompt), true));
});

test('a classifier failure keeps every tool', function (): void {
    DecisionAgent::fake(['ok']);
    Classification::fake(fn () => throw new RuntimeException('down'));

    runScopedJob();

    DecisionAgent::assertPrompted(fn (AgentPrompt $agentPrompt): bool => in_array(NowPlayingTool::class, scopedToolClasses($agentPrompt), true));
});

test('stuck-import events are never scoped or classified', function (): void {
    DecisionAgent::fake(['ok']);
    Classification::fake([]);

    runScopedJob('ManualInteractionRequired');

    Classification::assertNothingClassified();
    DecisionAgent::assertPrompted(fn (AgentPrompt $agentPrompt): bool => in_array(InspectStuckImportTool::class, scopedToolClasses($agentPrompt), true));
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
