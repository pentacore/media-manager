<?php

declare(strict_types=1);

use App\Ai\Agents\DecisionVerifierAgent;
use App\Ai\Decision\SecondOpinionCase;
use App\Ai\Decision\SecondOpinionVerdict;
use App\Ai\Decision\SecondOpinionVerifier;
use App\Enums\ClassificationGate;
use App\Enums\ClassificationVerdict;
use App\Enums\SecondOpinionEngine;
use App\Enums\SecondOpinionMode;
use App\Models\ActionRequest;
use App\Models\AiUsageRecord;
use App\Models\ClassificationOutcome;
use App\Services\Actions\ActionDescription;
use App\Services\AiBudget\AiBudgetExceededException;
use App\Services\AiBudget\AiBudgetGuard;
use App\Settings\AiSettings;
use App\Settings\DecisionAgentSettings;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Classification;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Mockery\MockInterface;

function secondOpinionCase(): SecondOpinionCase
{
    return new SecondOpinionCase(
        actionType: 'monitor_series',
        actionLabel: 'Monitor series',
        actionDescription: new ActionDescription('Monitor Severance', 'Turns monitoring on for Severance.'),
        payload: ['series_id' => 7, 'monitored' => true],
        rationale: 'The series was added unmonitored.',
        sourceService: 'sonarr',
        eventType: 'SeriesAdd',
        eventPayload: ['series' => ['id' => 7, 'title' => 'Severance']],
    );
}

function secondOpinionEngine(SecondOpinionEngine $secondOpinionEngine, float $threshold = 0.8): void
{
    resolve(DecisionAgentSettings::class)->setSecondOpinionEngine($secondOpinionEngine);
    resolve(DecisionAgentSettings::class)->setSecondOpinionJevThreshold($threshold);
}

test('the llm engine returns the agent verdict with its model', function (): void {
    DecisionVerifierAgent::fake([['agrees' => false, 'reason' => 'Wrong series.']]);

    $secondOpinionVerdict = resolve(SecondOpinionVerifier::class)->verify(secondOpinionCase());

    expect($secondOpinionVerdict->engine)->toBe('llm')
        ->and($secondOpinionVerdict->agrees)->toBeFalse()
        ->and($secondOpinionVerdict->failed)->toBeFalse()
        ->and($secondOpinionVerdict->reason)->toBe('Wrong series.')
        ->and($secondOpinionVerdict->model)->not->toBeNull();
    DecisionVerifierAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'Monitor Severance')
        && str_contains($prompt->prompt, '<untrusted_webhook_payload>'));
});

test('a verdict without a boolean agrees is a failed check', function (mixed $answer): void {
    DecisionVerifierAgent::fake([$answer]);

    $secondOpinionVerdict = resolve(SecondOpinionVerifier::class)->verify(secondOpinionCase());

    expect($secondOpinionVerdict->failed)->toBeTrue()->and($secondOpinionVerdict->agrees)->toBeFalse();
})->with([
    'string agrees' => [['agrees' => 'yes', 'reason' => 'ok']],
    'missing agrees' => [['reason' => 'ok']],
]);

test('an agent failure or a reached budget cap is a failed check', function (): void {
    DecisionVerifierAgent::fake(fn () => throw new RuntimeException('upstream 502'));
    expect(resolve(SecondOpinionVerifier::class)->verify(secondOpinionCase())->failed)->toBeTrue();

    $this->mock(AiBudgetGuard::class)->shouldReceive('enforce')->andThrow(new AiBudgetExceededException(10.0, 5.0));
    $secondOpinionVerdict = resolve(SecondOpinionVerifier::class)->verify(secondOpinionCase());
    expect($secondOpinionVerdict->failed)->toBeTrue()->and($secondOpinionVerdict->reason)->toBe('Skipped: the AI budget hard cap is reached.');
});

test('jev decides with the threshold inclusive', function (float $probability, bool $agrees, ClassificationVerdict $classificationVerdict): void {
    secondOpinionEngine(SecondOpinionEngine::Jev);
    Classification::fake([['decision' => new BooleanAnswer($probability)]]);
    DecisionVerifierAgent::fake()->preventStrayPrompts();

    $secondOpinionVerdict = resolve(SecondOpinionVerifier::class)->verify(secondOpinionCase());

    expect($secondOpinionVerdict->engine)->toBe('jev')
        ->and($secondOpinionVerdict->agrees)->toBe($agrees)
        ->and($secondOpinionVerdict->confidence)->toBe($probability)
        ->and($secondOpinionVerdict->jevVerdict)->toBe($classificationVerdict);
    DecisionVerifierAgent::assertNeverPrompted();
})->with([
    'at threshold' => [0.8, true, ClassificationVerdict::Passed],
    'below' => [0.79, false, ClassificationVerdict::Skipped],
]);

test('jev decides without a classifier answer is a failed check', function (): void {
    secondOpinionEngine(SecondOpinionEngine::Jev);
    Classification::fake(fn () => throw new RuntimeException('down'));

    $secondOpinionVerdict = resolve(SecondOpinionVerifier::class)->verify(secondOpinionCase());

    expect($secondOpinionVerdict->failed)->toBeTrue()->and($secondOpinionVerdict->engine)->toBe('jev');
});

test('jev then llm accepts a confident pass without the llm', function (): void {
    secondOpinionEngine(SecondOpinionEngine::JevThenLlm);
    resolve(AiSettings::class)->setClassificationAuditSampleRate(0.0);
    Classification::fake([['decision' => new BooleanAnswer(0.93)]]);
    DecisionVerifierAgent::fake()->preventStrayPrompts();

    $secondOpinionVerdict = resolve(SecondOpinionVerifier::class)->verify(secondOpinionCase());

    expect($secondOpinionVerdict->agrees)->toBeTrue()->and($secondOpinionVerdict->engine)->toBe('jev')->and($secondOpinionVerdict->jevVerdict)->toBe(ClassificationVerdict::Passed);
});

test('jev then llm hands doubt and audit samples to the llm, whose verdict is final', function (float $probability, float $auditRate, ClassificationVerdict $classificationVerdict): void {
    secondOpinionEngine(SecondOpinionEngine::JevThenLlm);
    resolve(AiSettings::class)->setClassificationAuditSampleRate($auditRate);
    Classification::fake([['decision' => new BooleanAnswer($probability)]]);
    DecisionVerifierAgent::fake([['agrees' => false, 'reason' => 'Not justified.']]);

    $secondOpinionVerdict = resolve(SecondOpinionVerifier::class)->verify(secondOpinionCase());

    expect($secondOpinionVerdict->engine)->toBe('llm')
        ->and($secondOpinionVerdict->agrees)->toBeFalse()
        ->and($secondOpinionVerdict->confidence)->toBe($probability)
        ->and($secondOpinionVerdict->jevVerdict)->toBe($classificationVerdict);
})->with([
    'doubt' => [0.4, 0.0, ClassificationVerdict::Fallback],
    'audit sample' => [0.95, 1.0, ClassificationVerdict::AuditRun],
]);

test('jev then llm falls through to the llm when jev is unavailable', function (): void {
    secondOpinionEngine(SecondOpinionEngine::JevThenLlm);
    Classification::fake(fn () => throw new RuntimeException('down'));
    DecisionVerifierAgent::fake([['agrees' => true, 'reason' => 'Fine.']]);

    $secondOpinionVerdict = resolve(SecondOpinionVerifier::class)->verify(secondOpinionCase());

    expect($secondOpinionVerdict->agrees)->toBeTrue()->and($secondOpinionVerdict->engine)->toBe('llm')->and($secondOpinionVerdict->confidence)->toBeNull();
});

test('verdicts hold or reject by mode, and a failure never rejects', function (bool $agrees, bool $failed, SecondOpinionMode $secondOpinionMode, bool $holds, bool $rejects): void {
    $verdict = $failed
        ? SecondOpinionVerdict::failure('llm', 'boom')
        : new SecondOpinionVerdict(engine: 'llm', agrees: $agrees);

    expect($verdict->holds($secondOpinionMode))->toBe($holds)
        ->and($verdict->rejects($secondOpinionMode))->toBe($rejects);
})->with([
    'agrees, hold' => [true, false, SecondOpinionMode::Hold, false, false],
    'agrees, reject' => [true, false, SecondOpinionMode::Reject, false, false],
    'disagrees, hold' => [false, false, SecondOpinionMode::Hold, true, false],
    'disagrees, reject' => [false, false, SecondOpinionMode::Reject, false, true],
    'failed, hold' => [false, true, SecondOpinionMode::Hold, true, false],
    'failed, reject' => [false, true, SecondOpinionMode::Reject, true, false],
]);

test('a jev answer is recorded and resolved by the llm verdict', function (): void {
    $secondOpinionVerdict = new SecondOpinionVerdict(engine: 'llm', agrees: false, reason: 'No.')
        ->withJev(0.4, ClassificationVerdict::Fallback, 0.8);

    resolve(SecondOpinionVerifier::class)->recordOutcome($secondOpinionVerdict, 42);

    $classificationOutcome = ClassificationOutcome::query()->sole();
    expect($classificationOutcome->gate)->toBe(ClassificationGate::SecondOpinion)
        ->and($classificationOutcome->subject_key)->toBe('action_request:42')
        ->and($classificationOutcome->probability)->toBe(0.4)
        ->and($classificationOutcome->outcome_positive)->toBeFalse()
        ->and($classificationOutcome->outcome_at)->not->toBeNull();
});

test('a jev-only disagreement stays open until a reviewer decides', function (): void {
    $verdict = new SecondOpinionVerdict(engine: 'jev', agrees: false, confidence: 0.3, jevVerdict: ClassificationVerdict::Skipped, jevThreshold: 0.8);
    $actionRequest = ActionRequest::factory()->secondOpinion(agrees: false, engine: 'jev', confidence: 0.3)->create();
    $secondOpinionVerifier = resolve(SecondOpinionVerifier::class);

    $secondOpinionVerifier->recordOutcome($verdict, $actionRequest->id);

    expect(ClassificationOutcome::query()->sole()->outcome_at)->toBeNull();

    $secondOpinionVerifier->resolveFromReview($actionRequest, approved: true);
    expect(ClassificationOutcome::query()->sole()->outcome_positive)->toBeTrue();
});

test('only types an automated proposer can produce support a second opinion', function (string $type, bool $supported): void {
    expect(SecondOpinionVerifier::supportsActionType($type))->toBe($supported);
})->with([
    ['monitor_series', true],
    ['delete_movie', true],
    ['resolve_manual_import', true],
    ['remove_stuck_download', true],
    ['grab_release', false],
    ['replace_media_file', false],
]);

test('a check too close to the run deadline is skipped as failed without asking anyone', function (SecondOpinionEngine $secondOpinionEngine, string $engine): void {
    secondOpinionEngine($secondOpinionEngine);
    Classification::fake()->preventStrayClassifications();
    DecisionVerifierAgent::fake()->preventStrayPrompts();

    $secondOpinionVerdict = resolve(SecondOpinionVerifier::class)->verify(secondOpinionCase(), now()->addSeconds(SecondOpinionVerifier::MIN_RUN_SECONDS_LEFT - 1));

    expect($secondOpinionVerdict->failed)->toBeTrue()
        ->and($secondOpinionVerdict->agrees)->toBeFalse()
        ->and($secondOpinionVerdict->engine)->toBe($engine)
        ->and($secondOpinionVerdict->reason)->toBe('Skipped: the decision run is out of time.');
    DecisionVerifierAgent::assertNeverPrompted();
    Classification::assertNothingClassified();
})->with([
    'llm' => [SecondOpinionEngine::Llm, 'llm'],
    'jev' => [SecondOpinionEngine::Jev, 'jev'],
    'jev then llm' => [SecondOpinionEngine::JevThenLlm, 'llm'],
]);

test('the llm call keeps its full timeout when the run has time to spare', function (): void {
    DecisionVerifierAgent::fake([['agrees' => true, 'reason' => 'Fine.']]);

    $secondOpinionVerdict = resolve(SecondOpinionVerifier::class)->verify(secondOpinionCase(), now()->addSeconds(200));

    expect($secondOpinionVerdict->failed)->toBeFalse()->and($secondOpinionVerdict->agrees)->toBeTrue();
    DecisionVerifierAgent::assertPrompted(fn ($prompt): bool => $prompt->timeout === SecondOpinionVerifier::LLM_TIMEOUT_SECONDS);
});

test('the llm call is cut to fit what jev left of the run', function (): void {
    $this->freezeTime();
    secondOpinionEngine(SecondOpinionEngine::JevThenLlm);
    Classification::fake([function (): array {
        $this->travel(20)->seconds();

        return ['decision' => new BooleanAnswer(0.4)];
    }]);
    DecisionVerifierAgent::fake([['agrees' => true, 'reason' => 'Fine.']]);

    $secondOpinionVerdict = resolve(SecondOpinionVerifier::class)->verify(secondOpinionCase(), now()->addSeconds(65));

    expect($secondOpinionVerdict->failed)->toBeFalse()->and($secondOpinionVerdict->agrees)->toBeTrue();
    DecisionVerifierAgent::assertPrompted(fn ($prompt): bool => $prompt->timeout === 15);
});

test('an llm call jev left too little time for is a failed check that keeps the jev answer', function (): void {
    $this->freezeTime();
    secondOpinionEngine(SecondOpinionEngine::JevThenLlm);
    Classification::fake([function (): array {
        $this->travel(30)->seconds();

        return ['decision' => new BooleanAnswer(0.4)];
    }]);
    DecisionVerifierAgent::fake()->preventStrayPrompts();

    $secondOpinionVerdict = resolve(SecondOpinionVerifier::class)->verify(secondOpinionCase(), now()->addSeconds(65));

    expect($secondOpinionVerdict->failed)->toBeTrue()
        ->and($secondOpinionVerdict->reason)->toBe('Skipped: the decision run is out of time.')
        ->and($secondOpinionVerdict->confidence)->toBe(0.4)
        ->and($secondOpinionVerdict->jevVerdict)->toBe(ClassificationVerdict::Fallback);
    DecisionVerifierAgent::assertNeverPrompted();
});

test('an unexpected failure is labelled with the configured engine', function (?SecondOpinionEngine $secondOpinionEngine, string $engine): void {
    $this->mock(DecisionAgentSettings::class, function (MockInterface $mock) use ($secondOpinionEngine): void {
        $secondOpinionEngine instanceof SecondOpinionEngine
            ? $mock->shouldReceive('secondOpinionEngine')->andReturn($secondOpinionEngine)
            : $mock->shouldReceive('secondOpinionEngine')->andThrow(new RuntimeException('settings unavailable'));
        $mock->shouldReceive('secondOpinionJevThreshold')->andThrow(new RuntimeException('settings unavailable'));
    });
    DecisionVerifierAgent::fake()->preventStrayPrompts();

    $secondOpinionVerdict = resolve(SecondOpinionVerifier::class)->verify(secondOpinionCase());

    expect($secondOpinionVerdict->failed)->toBeTrue()
        ->and($secondOpinionVerdict->engine)->toBe($engine)
        ->and($secondOpinionVerdict->reason)->toBe('The second-opinion check failed unexpectedly.');
})->with([
    'jev' => [SecondOpinionEngine::Jev, 'jev'],
    'jev then llm' => [SecondOpinionEngine::JevThenLlm, 'llm'],
    'engine unreadable' => [null, 'llm'],
]);

test('a cascade whose llm fails after a doubtful jev answer holds and leaves the jev answer open', function (): void {
    secondOpinionEngine(SecondOpinionEngine::JevThenLlm);
    Classification::fake([['decision' => new BooleanAnswer(0.4)]]);
    DecisionVerifierAgent::fake(fn () => throw new RuntimeException('upstream 502'));
    $secondOpinionVerifier = resolve(SecondOpinionVerifier::class);

    $secondOpinionVerdict = $secondOpinionVerifier->verify(secondOpinionCase());
    $secondOpinionVerifier->recordOutcome($secondOpinionVerdict, 42);

    $classificationOutcome = ClassificationOutcome::query()->sole();
    expect($secondOpinionVerdict->failed)->toBeTrue()
        ->and($secondOpinionVerdict->agrees)->toBeFalse()
        ->and($secondOpinionVerdict->holds(SecondOpinionMode::Reject))->toBeTrue()
        ->and($secondOpinionVerdict->confidence)->toBe(0.4)
        ->and($secondOpinionVerdict->jevVerdict)->toBe(ClassificationVerdict::Fallback)
        ->and($classificationOutcome->subject_key)->toBe('action_request:42')
        ->and($classificationOutcome->probability)->toBe(0.4)
        ->and($classificationOutcome->outcome_at)->toBeNull();
});

test('the verifier leaves room for reasoning tokens before its structured answer', function (): void {
    $reflectionClass = new ReflectionClass(DecisionVerifierAgent::class);

    expect($reflectionClass->getAttributes(MaxTokens::class)[0]->newInstance()->value)->toBe(4096);
});

test('a verdict serialises to the shape stored on the action request', function (): void {
    $secondOpinionVerdict = new SecondOpinionVerdict(engine: 'llm', agrees: false, failed: false, confidence: 0.4, reason: 'No.', provider: 'openai', model: 'gpt-5-mini', invocationId: 'inv-7');

    expect($secondOpinionVerdict->toArray(SecondOpinionMode::Reject))->toBe([
        'engine' => 'llm',
        'mode' => 'reject',
        'agrees' => false,
        'failed' => false,
        'confidence' => 0.4,
        'reason' => 'No.',
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'invocation_id' => 'inv-7',
    ]);
});

test('an llm verdict carries the invocation id of the verifier call', function (): void {
    DecisionVerifierAgent::fake([['agrees' => true, 'reason' => 'Fine.']]);

    $secondOpinionVerdict = resolve(SecondOpinionVerifier::class)->verify(secondOpinionCase());

    expect($secondOpinionVerdict->invocationId)->toBeString()->not->toBe('')
        ->and(AiUsageRecord::query()->where('invocation_id', $secondOpinionVerdict->invocationId)->value('agent_class'))->toBe(DecisionVerifierAgent::class);
});

test('a cascade llm verdict keeps its invocation id next to the jev answer', function (): void {
    secondOpinionEngine(SecondOpinionEngine::JevThenLlm);
    resolve(AiSettings::class)->setClassificationAuditSampleRate(0.0);
    Classification::fake([['decision' => new BooleanAnswer(0.4)]]);
    DecisionVerifierAgent::fake([['agrees' => false, 'reason' => 'Not justified.']]);

    $secondOpinionVerdict = resolve(SecondOpinionVerifier::class)->verify(secondOpinionCase());

    expect($secondOpinionVerdict->engine)->toBe('llm')
        ->and($secondOpinionVerdict->confidence)->toBe(0.4)
        ->and($secondOpinionVerdict->invocationId)->toBeString()->not->toBe('');
});

test('a jev-only verdict and a failed check have no invocation id', function (): void {
    secondOpinionEngine(SecondOpinionEngine::Jev);
    Classification::fake([['decision' => new BooleanAnswer(0.9)]]);

    expect(resolve(SecondOpinionVerifier::class)->verify(secondOpinionCase())->invocationId)->toBeNull()
        ->and(SecondOpinionVerdict::failure('llm', 'boom')->invocationId)->toBeNull();
});

test('resolving the review of a request without a second opinion touches no outcome rows', function (): void {
    $actionRequest = ActionRequest::factory()->create(['second_opinion' => null]);
    $classificationOutcome = ClassificationOutcome::factory()->create([
        'gate' => ClassificationGate::SecondOpinion,
        'subject_key' => SecondOpinionVerifier::outcomeSubjectKey($actionRequest->id),
        'verdict' => ClassificationVerdict::Fallback,
        'outcome_at' => null,
        'outcome_positive' => null,
    ]);

    resolve(SecondOpinionVerifier::class)->resolveFromReview($actionRequest, approved: true);

    expect($classificationOutcome->fresh()->outcome_at)->toBeNull()
        ->and($classificationOutcome->fresh()->outcome_positive)->toBeNull();
});
