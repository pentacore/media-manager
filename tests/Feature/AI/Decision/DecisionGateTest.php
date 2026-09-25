<?php

declare(strict_types=1);

use App\Ai\Agents\DecisionAgent;
use App\Ai\Classification\Classifier;
use App\Enums\AgentDecisionStatus;
use App\Jobs\RunDecisionAgent;
use App\Models\AgentDecision;
use App\Services\AiBudget\AiBudgetGuard;
use App\Settings\AiSettings;
use App\Settings\DecisionAgentSettings;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
    resolve(DecisionAgentSettings::class)->setEnabled(true);
    resolve(AiSettings::class)->setDecisionGateEnabled(true);
    resolve(AiSettings::class)->setDecisionGateThreshold(0.3);
    DecisionAgent::fake(['Nothing to do.']);
});

function runGateJob(string $eventType, ?int $seriesId = null): void
{
    (new RunDecisionAgent(null, 'sonarr', $eventType, ['series' => ['id' => $seriesId ?? random_int(1, 99999)], 'eventType' => $eventType]))->handle(
        resolve(DecisionAgentSettings::class),
        resolve(AiBudgetGuard::class),
        resolve(AiSettings::class),
        resolve(Classifier::class),
    );
}

test('an event classified below the threshold is skipped and recorded', function (): void {
    Classification::fake([['decision' => new BooleanAnswer(0.1)]]);

    runGateJob('Grab');

    DecisionAgent::assertNeverPrompted();
    expect(AgentDecision::sole())
        ->status->toBe(AgentDecisionStatus::SkippedByGate)
        ->summary->toContain('10%');
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
