<?php

declare(strict_types=1);

use App\Ai\Agents\DecisionAgent;
use App\Ai\Decision\DecisionRunContext;
use App\Enums\AgentDecisionStatus;
use App\Enums\ClassificationGate;
use App\Enums\ClassificationVerdict;
use App\Jobs\RunDecisionAgent;
use App\Models\ActionRequest;
use App\Models\AgentDecision;
use App\Models\ClassificationOutcome;
use App\Settings\AiSettings;
use App\Settings\DecisionAgentSettings;
use Illuminate\Support\Lottery;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
    resolve(DecisionAgentSettings::class)->setEnabled(true);
    resolve(AiSettings::class)->setDecisionGateEnabled(true);
    resolve(AiSettings::class)->setDecisionGateThreshold(0.3);
    DecisionAgent::fake(['Nothing to do.']);
    Lottery::alwaysLose();
});

afterEach(function (): void {
    Lottery::determineResultsNormally();
});

function runGateJob(string $eventType, ?int $seriesId = null): void
{
    app()->call([new RunDecisionAgent(null, 'sonarr', $eventType, ['series' => ['id' => $seriesId ?? random_int(1, 99999)], 'eventType' => $eventType]), 'handle']);
}

test('an event classified below the threshold is skipped and recorded', function (): void {
    Classification::fake([['decision' => new BooleanAnswer(0.1)]]);

    runGateJob('Grab');

    DecisionAgent::assertNeverPrompted();
    expect(AgentDecision::sole())
        ->status->toBe(AgentDecisionStatus::SkippedByGate)
        ->summary->toContain('10%')
        ->summary->toContain('Does this media-server webhook event require an operator action');
});

test('an event above the threshold runs the agent', function (): void {
    Classification::fake([['decision' => new BooleanAnswer(0.9)]]);

    runGateJob('Grab');

    DecisionAgent::assertPrompted(fn (): bool => true);
});

test('a classifier failure fails open and runs the agent', function (): void {
    Classification::fake(fn () => throw new RuntimeException('down'));

    runGateJob('Grab');

    DecisionAgent::assertPrompted(fn (): bool => true);
});

test('stuck-import events always bypass the gate', function (): void {
    Classification::fake([['decision' => new BooleanAnswer(0.0)]]);

    runGateJob('ManualInteractionRequired');

    DecisionAgent::assertPrompted(fn (): bool => true);
    Classification::assertNothingClassified();
});

test('a disabled gate never classifies', function (): void {
    resolve(AiSettings::class)->setDecisionGateEnabled(false);
    Classification::fake([['decision' => new BooleanAnswer(0.0)]]);

    runGateJob('Grab');

    DecisionAgent::assertPrompted(fn (): bool => true);
    Classification::assertNothingClassified();
});

test('an event the gate skips does not start the subject cooldown', function (): void {
    Classification::fake([['decision' => new BooleanAnswer(0.1)]]);

    runGateJob('Grab', seriesId: 42);
    runGateJob('ManualInteractionRequired', seriesId: 42);

    DecisionAgent::assertPrompted(fn (): bool => true);
    expect(AgentDecision::query()->orderBy('id')->pluck('status')->all())->toBe([AgentDecisionStatus::SkippedByGate, AgentDecisionStatus::NoAction]);
});

test('an agent run still starts the subject cooldown', function (): void {
    Classification::fake([['decision' => new BooleanAnswer(0.9)], ['decision' => new BooleanAnswer(0.9)]]);

    runGateJob('Grab', seriesId: 43);
    runGateJob('Grab', seriesId: 43);

    expect(AgentDecision::count())->toBe(1);
});

test('a passing gate records its probability and threshold', function (): void {
    Classification::fake([['decision' => new BooleanAnswer(0.9)]]);

    runGateJob('Grab');

    expect(ClassificationOutcome::sole())
        ->gate->toBe(ClassificationGate::DecisionGate)
        ->verdict->toBe(ClassificationVerdict::Passed)
        ->probability->toBe(0.9)
        ->threshold->toBe(0.3);
});

test('a skipped event records a skipped outcome with no result', function (): void {
    Lottery::alwaysLose();
    Classification::fake([['decision' => new BooleanAnswer(0.1)]]);

    runGateJob('Grab');

    expect(ClassificationOutcome::sole())
        ->verdict->toBe(ClassificationVerdict::Skipped)
        ->outcome_at->toBeNull();
});

test('a sampled below-threshold event runs as an audit run and says so', function (): void {
    Lottery::alwaysWin();
    Classification::fake([['decision' => new BooleanAnswer(0.1)]]);

    runGateJob('Grab');

    DecisionAgent::assertPrompted(fn (): bool => true);
    expect(ClassificationOutcome::sole()->verdict)->toBe(ClassificationVerdict::AuditRun)
        ->and(AgentDecision::sole()->summary)->toStartWith('Audit run');
});

test('a zero audit rate never runs a below-threshold event', function (): void {
    Lottery::alwaysWin();
    resolve(AiSettings::class)->setClassificationAuditSampleRate(0.0);
    Classification::fake([['decision' => new BooleanAnswer(0.1)]]);

    runGateJob('Grab');

    DecisionAgent::assertNeverPrompted();
});

test('a run that proposes nothing resolves the gate outcome as negative', function (): void {
    Classification::fake([['decision' => new BooleanAnswer(0.9)]]);

    runGateJob('Grab');

    expect(ClassificationOutcome::sole())->outcome_positive->toBeFalse()->outcome_detail->toBe('no action');
});

test('a run that queues an action resolves the gate outcome as positive', function (): void {
    Classification::fake([['decision' => new BooleanAnswer(0.9)]]);
    DecisionAgent::fake(function (): string {
        resolve(DecisionRunContext::class)->recordQueued(ActionRequest::factory()->create(['type' => 'add_series'])->id, true);

        return 'Proposed adding the series.';
    });

    runGateJob('Grab');

    expect(ClassificationOutcome::sole())->outcome_positive->toBeTrue()->outcome_detail->toBe('1 action(s) proposed');
});
