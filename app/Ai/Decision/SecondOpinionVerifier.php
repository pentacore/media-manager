<?php

declare(strict_types=1);

namespace App\Ai\Decision;

use App\Ai\Agents\DecisionVerifierAgent;
use App\Ai\Classification\ClassificationOutcomeRecorder;
use App\Ai\Classification\Classifier;
use App\Ai\TaskModelResolver;
use App\Ai\Tools\Decision\ProposeActionTool;
use App\Enums\AiTask;
use App\Enums\ClassificationGate;
use App\Enums\ClassificationVerdict;
use App\Enums\SecondOpinionEngine;
use App\Enums\SecondOpinionMode;
use App\Models\ActionRequest;
use App\Models\AiTaskModel;
use App\Models\ClassificationOutcome;
use App\Services\AiBudget\AiBudgetExceededException;
use App\Services\AiBudget\AiBudgetGuard;
use App\Settings\DecisionAgentSettings;
use App\Support\UpstreamErrorText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Gives the second opinion on an automated proposal with the engine and
 * threshold of its policy (falling back to the Decision Agent defaults),
 * and tracks Jev's answers under the SecondOpinion classification gate.
 * Never throws: every failure is a failed verdict, which the orchestrator
 * treats as Hold.
 */
final readonly class SecondOpinionVerifier
{
    public const string JEV_QUESTION = 'Is this proposed media-server action correct, proportionate and justified by the triggering event?';

    public const int LLM_TIMEOUT_SECONDS = 30;

    /**
     * A check that would start with less of the decision run left than this
     * is skipped as failed (held), so the run can still record its summary.
     */
    public const int MIN_RUN_SECONDS_LEFT = 60;

    /**
     * Run time kept back after the LLM check for the proposer to finish.
     */
    public const int RUN_SECONDS_RESERVED = 30;

    public const string OUT_OF_TIME_REASON = 'Skipped: the decision run is out of time.';

    /**
     * The shortest LLM call per provider worth making.
     */
    private const int MIN_LLM_TIMEOUT_SECONDS = 10;

    private const string OUTCOME_QUESTION = 'second_opinion';

    /**
     * Action types an automated proposer can produce besides the
     * DecisionAgent's ProposeActionTool types.
     */
    private const array STUCK_IMPORT_TYPES = ['resolve_manual_import', 'remove_stuck_download'];

    public function __construct(
        private DecisionAgentSettings $decisionAgentSettings,
        private Classifier $classifier,
        private ClassificationOutcomeRecorder $classificationOutcomeRecorder,
        private AiBudgetGuard $aiBudgetGuard,
        private TaskModelResolver $taskModelResolver,
    ) {}

    /**
     * @return list<string>
     */
    public static function supportedActionTypes(): array
    {
        return [...ProposeActionTool::ALLOWED_TYPES, ...self::STUCK_IMPORT_TYPES];
    }

    public static function supportsActionType(string $type): bool
    {
        return in_array($type, self::supportedActionTypes(), true);
    }

    public static function outcomeSubjectKey(int $actionRequestId): string
    {
        return ClassificationOutcome::subjectKey('action_request', $actionRequestId);
    }

    /**
     * The policy for one proposed action: the event's mode override replaces
     * the action type's rule (for supported types only), and the event's
     * engine, threshold and verifier model tiers apply.
     */
    public function policyFor(string $actionType, SecondOpinionMode $actionMode, ?string $eventKey): SecondOpinionPolicy
    {
        $eventOverride = $this->decisionAgentSettings->secondOpinionEventOverride($eventKey);
        $eventMode = $eventOverride['mode'] ?? null;
        $supported = self::supportsActionType($actionType);
        $secondOpinionEngine = $this->decisionAgentSettings->secondOpinionEngine($eventKey);

        return new SecondOpinionPolicy(
            mode: match (true) {
                ! $eventMode instanceof SecondOpinionMode => $actionMode,
                $supported => $eventMode,
                default => SecondOpinionMode::Off,
            },
            engine: $secondOpinionEngine,
            jevThreshold: $this->decisionAgentSettings->secondOpinionJevThreshold($eventKey),
            eventKey: $eventKey,
            overridden: $eventKey !== null && $this->eventOverrideTookEffect($eventOverride, $secondOpinionEngine, $eventKey),
            skipsActionRule: $supported && $eventMode === SecondOpinionMode::Off && $actionMode !== SecondOpinionMode::Off,
        );
    }

    /**
     * $deadline is when the decision run must be done (null outside a run):
     * a check too close to it is skipped, and the LLM call is cut to fit.
     */
    public function verify(SecondOpinionCase $secondOpinionCase, ?CarbonImmutable $deadline = null, ?SecondOpinionPolicy $secondOpinionPolicy = null): SecondOpinionVerdict
    {
        $secondOpinionEngine = null;

        try {
            $secondOpinionEngine = $secondOpinionPolicy instanceof SecondOpinionPolicy
                ? $secondOpinionPolicy->engine
                : $this->decisionAgentSettings->secondOpinionEngine();
            $secondOpinionPolicy ??= $this->globalPolicy($secondOpinionEngine);

            if ($deadline instanceof CarbonImmutable && $this->secondsLeft($deadline) < self::MIN_RUN_SECONDS_LEFT) {
                return SecondOpinionVerdict::failure($this->failureEngine($secondOpinionEngine), self::OUT_OF_TIME_REASON);
            }

            return match ($secondOpinionEngine) {
                SecondOpinionEngine::Llm => $this->askLlm($secondOpinionCase, $deadline, $secondOpinionPolicy->eventKey),
                SecondOpinionEngine::Jev => $this->jevDecides($secondOpinionCase, $secondOpinionPolicy->jevThreshold),
                SecondOpinionEngine::JevThenLlm => $this->jevThenLlm($secondOpinionCase, $deadline, $secondOpinionPolicy),
            };
        } catch (Throwable $throwable) {
            Log::warning('SecondOpinionVerifier: check failed unexpectedly', [
                'type' => $secondOpinionCase->actionType,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return SecondOpinionVerdict::failure($this->failureEngine($secondOpinionEngine), 'The second-opinion check failed unexpectedly.');
        }
    }

    /**
     * Store Jev's answer for this action request and, when the LLM ran after
     * it, resolve it with the LLM's verdict.
     *
     * Otherwise the row stays open until a human reviews the request
     * (resolveFromReview()). Rows for requests born Rejected or auto-run
     * (never held for review) stay open on purpose: only a human review or
     * the cascade's LLM resolves them.
     */
    public function recordOutcome(SecondOpinionVerdict $secondOpinionVerdict, int $actionRequestId): void
    {
        if ($secondOpinionVerdict->confidence === null || ! $secondOpinionVerdict->jevVerdict instanceof ClassificationVerdict) {
            return;
        }

        $subjectKey = self::outcomeSubjectKey($actionRequestId);

        $this->classificationOutcomeRecorder->record(
            ClassificationGate::SecondOpinion,
            $subjectKey,
            self::OUTCOME_QUESTION,
            $secondOpinionVerdict->confidence,
            $secondOpinionVerdict->jevVerdict,
            $secondOpinionVerdict->jevThreshold,
        );

        if ($secondOpinionVerdict->engine === SecondOpinionVerdict::ENGINE_LLM && ! $secondOpinionVerdict->failed) {
            $this->classificationOutcomeRecorder->resolve(
                ClassificationGate::SecondOpinion,
                $subjectKey,
                $secondOpinionVerdict->agrees,
                $secondOpinionVerdict->agrees ? 'the LLM verifier agreed' : 'the LLM verifier disagreed',
                [ClassificationVerdict::AuditRun, ClassificationVerdict::Fallback],
            );
        }
    }

    /**
     * A reviewer's approve/reject is the real outcome of a held action.
     */
    public function resolveFromReview(ActionRequest $actionRequest, bool $approved): void
    {
        if ($actionRequest->second_opinion === null || SecondOpinionPolicy::isSkipMarker($actionRequest->second_opinion)) {
            return;
        }

        $this->classificationOutcomeRecorder->resolve(
            ClassificationGate::SecondOpinion,
            self::outcomeSubjectKey($actionRequest->id),
            $approved,
            $approved ? 'approved by a reviewer' : 'rejected by a reviewer',
            [ClassificationVerdict::Passed, ClassificationVerdict::AuditRun, ClassificationVerdict::Fallback, ClassificationVerdict::Skipped],
        );
    }

    private function jevDecides(SecondOpinionCase $secondOpinionCase, float $threshold): SecondOpinionVerdict
    {
        $probability = $this->askJev($secondOpinionCase);

        if ($probability === null) {
            return SecondOpinionVerdict::failure(SecondOpinionVerdict::ENGINE_JEV, 'The classifier is not configured or did not answer.');
        }

        $agrees = $probability >= $threshold;

        return new SecondOpinionVerdict(
            engine: SecondOpinionVerdict::ENGINE_JEV,
            agrees: $agrees,
            confidence: $probability,
            reason: $this->jevReason($probability, $threshold),
            jevVerdict: $agrees ? ClassificationVerdict::Passed : ClassificationVerdict::Skipped,
            jevThreshold: $threshold,
        );
    }

    /**
     * Jev answers confident passes alone; doubt, audit samples and an
     * unavailable classifier go to the LLM, whose verdict is final. Jev
     * never rejects alone here.
     */
    private function jevThenLlm(SecondOpinionCase $secondOpinionCase, ?CarbonImmutable $deadline, SecondOpinionPolicy $secondOpinionPolicy): SecondOpinionVerdict
    {
        $threshold = $secondOpinionPolicy->jevThreshold;
        $probability = $this->askJev($secondOpinionCase);

        if ($probability === null) {
            return $this->askLlm($secondOpinionCase, $deadline, $secondOpinionPolicy->eventKey);
        }

        $confident = $probability >= $threshold;

        if ($confident && ! $this->classificationOutcomeRecorder->shouldAudit()) {
            return new SecondOpinionVerdict(
                engine: SecondOpinionVerdict::ENGINE_JEV,
                agrees: true,
                confidence: $probability,
                reason: $this->jevReason($probability, $threshold),
                jevVerdict: ClassificationVerdict::Passed,
                jevThreshold: $threshold,
            );
        }

        return $this->askLlm($secondOpinionCase, $deadline, $secondOpinionPolicy->eventKey)->withJev(
            $probability,
            $confident ? ClassificationVerdict::AuditRun : ClassificationVerdict::Fallback,
            $threshold,
        );
    }

    private function askJev(SecondOpinionCase $secondOpinionCase): ?float
    {
        return $this->classifier->probability(self::class, $secondOpinionCase->toClassificationState(), self::JEV_QUESTION);
    }

    private function askLlm(SecondOpinionCase $secondOpinionCase, ?CarbonImmutable $deadline, ?string $eventKey): SecondOpinionVerdict
    {
        try {
            $this->aiBudgetGuard->enforce();

            $decisionVerifierAgent = resolve(DecisionVerifierAgent::class)->forEvent($eventKey);
            $timeout = $this->llmTimeout($decisionVerifierAgent, $deadline);

            if ($timeout === null) {
                return SecondOpinionVerdict::failure(SecondOpinionVerdict::ENGINE_LLM, self::OUT_OF_TIME_REASON);
            }

            $agentResponse = $decisionVerifierAgent->prompt($secondOpinionCase->toPrompt(), timeout: $timeout);

            $agrees = $agentResponse['agrees'] ?? null;
            $reason = $agentResponse['reason'] ?? null;

            throw_unless(is_bool($agrees), RuntimeException::class, 'The verifier returned no verdict.');

            return new SecondOpinionVerdict(
                engine: SecondOpinionVerdict::ENGINE_LLM,
                agrees: $agrees,
                reason: is_string($reason) && trim($reason) !== '' ? Str::limit(trim($reason), 500) : null,
                provider: $agentResponse->meta->provider ?? null,
                model: $agentResponse->meta->model ?? null,
                invocationId: $agentResponse->invocationId,
            );
        } catch (AiBudgetExceededException) {
            return SecondOpinionVerdict::failure(SecondOpinionVerdict::ENGINE_LLM, 'Skipped: the AI budget hard cap is reached.');
        } catch (Throwable $throwable) {
            Log::warning('SecondOpinionVerifier: LLM verifier failed', [
                'type' => $secondOpinionCase->actionType,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return SecondOpinionVerdict::failure(
                SecondOpinionVerdict::ENGINE_LLM,
                UpstreamErrorText::sanitize($throwable->getMessage(), 300, UpstreamErrorText::AGENT_EMPTY_FALLBACK),
            );
        }
    }

    /**
     * The per-provider timeout that lets the whole failover chain finish
     * before the run's reserve, or null when that leaves too little time.
     */
    private function llmTimeout(DecisionVerifierAgent $decisionVerifierAgent, ?CarbonImmutable $deadline): ?int
    {
        if (! $deadline instanceof CarbonImmutable) {
            return self::LLM_TIMEOUT_SECONDS;
        }

        $timeout = DecisionRunContext::failoverTimeout(
            $this->secondsLeft($deadline),
            self::RUN_SECONDS_RESERVED,
            count($decisionVerifierAgent->provider()),
            self::LLM_TIMEOUT_SECONDS,
        );

        return $timeout >= self::MIN_LLM_TIMEOUT_SECONDS ? $timeout : null;
    }

    /**
     * Whether the event's override changed how the check runs: its mode or
     * engine, its threshold under an engine that asks Jev, or verifier tiers
     * that pick a provider, model or reasoning (not all-inherit) under an
     * engine that can ask the LLM.
     *
     * @param  array{mode: SecondOpinionMode|null, engine: SecondOpinionEngine|null, jev_threshold: float|null}|null  $eventOverride
     */
    private function eventOverrideTookEffect(?array $eventOverride, SecondOpinionEngine $secondOpinionEngine, string $eventKey): bool
    {
        if ($eventOverride !== null && ($eventOverride['mode'] instanceof SecondOpinionMode || $eventOverride['engine'] instanceof SecondOpinionEngine)) {
            return true;
        }

        if (($eventOverride['jev_threshold'] ?? null) !== null && $secondOpinionEngine->usesClassifier()) {
            return true;
        }

        return $secondOpinionEngine->canAskLlm() && $this->taskModelResolver->tiers(AiTask::DecisionVerifier, $eventKey)->contains(
            static fn (AiTaskModel $aiTaskModel): bool => filled($aiTaskModel->provider) || filled($aiTaskModel->model) || $aiTaskModel->reasoning !== null,
        );
    }

    /**
     * The Decision Agent defaults; verify() ignores the mode.
     */
    private function globalPolicy(SecondOpinionEngine $secondOpinionEngine): SecondOpinionPolicy
    {
        return new SecondOpinionPolicy(SecondOpinionMode::Off, $secondOpinionEngine, $this->decisionAgentSettings->secondOpinionJevThreshold());
    }

    private function secondsLeft(CarbonImmutable $deadline): int
    {
        return $deadline->getTimestamp() - now()->getTimestamp();
    }

    /**
     * Whose answer a failed check stands in for: Jev's in jev mode, the
     * LLM's otherwise (including when the engine could not be read).
     */
    private function failureEngine(?SecondOpinionEngine $secondOpinionEngine): string
    {
        return $secondOpinionEngine === SecondOpinionEngine::Jev ? SecondOpinionVerdict::ENGINE_JEV : SecondOpinionVerdict::ENGINE_LLM;
    }

    private function jevReason(float $probability, float $threshold): string
    {
        return sprintf('Classifier confidence %.2f (threshold %.2f).', $probability, $threshold);
    }
}
