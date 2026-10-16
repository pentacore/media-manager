<?php

declare(strict_types=1);

namespace App\Ai\Decision;

use App\Ai\Agents\DecisionVerifierAgent;
use App\Ai\Classification\ClassificationOutcomeRecorder;
use App\Ai\Classification\Classifier;
use App\Ai\Tools\Decision\ProposeActionTool;
use App\Enums\ClassificationGate;
use App\Enums\ClassificationVerdict;
use App\Enums\SecondOpinionEngine;
use App\Models\ActionRequest;
use App\Models\ClassificationOutcome;
use App\Services\AiBudget\AiBudgetExceededException;
use App\Services\AiBudget\AiBudgetGuard;
use App\Settings\DecisionAgentSettings;
use App\Support\UpstreamErrorText;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Throwable;

/**
 * Gives the second opinion on an automated proposal with the engine the
 * Decision Agent settings choose, and tracks Jev's answers under the
 * SecondOpinion classification gate. Never throws: every failure is a
 * failed verdict, which the orchestrator treats as Hold.
 */
final readonly class SecondOpinionVerifier
{
    public const string JEV_QUESTION = 'Is this proposed media-server action correct, proportionate and justified by the triggering event?';

    public const int LLM_TIMEOUT_SECONDS = 30;

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
    ) {}

    public static function supportsActionType(string $type): bool
    {
        return in_array($type, [...ProposeActionTool::ALLOWED_TYPES, ...self::STUCK_IMPORT_TYPES], true);
    }

    public static function outcomeSubjectKey(int $actionRequestId): string
    {
        return ClassificationOutcome::subjectKey('action_request', $actionRequestId);
    }

    public function verify(SecondOpinionCase $secondOpinionCase): SecondOpinionVerdict
    {
        try {
            return match ($this->decisionAgentSettings->secondOpinionEngine()) {
                SecondOpinionEngine::Llm => $this->askLlm($secondOpinionCase),
                SecondOpinionEngine::Jev => $this->jevDecides($secondOpinionCase),
                SecondOpinionEngine::JevThenLlm => $this->jevThenLlm($secondOpinionCase),
            };
        } catch (Throwable $throwable) {
            Log::warning('SecondOpinionVerifier: check failed unexpectedly', [
                'type' => $secondOpinionCase->actionType,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return SecondOpinionVerdict::failure(SecondOpinionVerdict::ENGINE_LLM, 'The second-opinion check failed unexpectedly.');
        }
    }

    /**
     * Store Jev's answer for this action request and, when the LLM ran after
     * it, resolve it with the LLM's verdict.
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
        if ($actionRequest->second_opinion === null) {
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

    private function jevDecides(SecondOpinionCase $secondOpinionCase): SecondOpinionVerdict
    {
        $threshold = $this->decisionAgentSettings->secondOpinionJevThreshold();
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
    private function jevThenLlm(SecondOpinionCase $secondOpinionCase): SecondOpinionVerdict
    {
        $threshold = $this->decisionAgentSettings->secondOpinionJevThreshold();
        $probability = $this->askJev($secondOpinionCase);

        if ($probability === null) {
            return $this->askLlm($secondOpinionCase);
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

        return $this->askLlm($secondOpinionCase)->withJev(
            $probability,
            $confident ? ClassificationVerdict::AuditRun : ClassificationVerdict::Fallback,
            $threshold,
        );
    }

    private function askJev(SecondOpinionCase $secondOpinionCase): ?float
    {
        return $this->classifier->probability(self::class, $secondOpinionCase->toClassificationState(), self::JEV_QUESTION);
    }

    private function askLlm(SecondOpinionCase $secondOpinionCase): SecondOpinionVerdict
    {
        try {
            $this->aiBudgetGuard->enforce();

            $agentResponse = resolve(DecisionVerifierAgent::class)->prompt($secondOpinionCase->toPrompt(), timeout: self::LLM_TIMEOUT_SECONDS);

            $agrees = $agentResponse instanceof StructuredAgentResponse ? ($agentResponse['agrees'] ?? null) : null;
            $reason = $agentResponse instanceof StructuredAgentResponse ? ($agentResponse['reason'] ?? null) : null;

            throw_unless(is_bool($agrees), RuntimeException::class, 'The verifier returned no verdict.');

            return new SecondOpinionVerdict(
                engine: SecondOpinionVerdict::ENGINE_LLM,
                agrees: $agrees,
                reason: is_string($reason) && trim($reason) !== '' ? Str::limit(trim($reason), 500) : null,
                provider: $agentResponse->meta->provider ?? null,
                model: $agentResponse->meta->model ?? null,
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

    private function jevReason(float $probability, float $threshold): string
    {
        return sprintf('Classifier confidence %.2f (threshold %.2f).', $probability, $threshold);
    }
}
