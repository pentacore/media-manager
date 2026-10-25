<?php

declare(strict_types=1);

namespace App\Ai\Decision;

use App\Enums\ClassificationVerdict;
use App\Enums\SecondOpinionMode;

/**
 * One second opinion. $engine names whose answer is final ('llm' or 'jev');
 * $confidence is Jev's probability whenever Jev was asked; $jevVerdict and
 * $jevThreshold say how Jev's answer was used, for outcome tracking;
 * $invocationId links the LLM call's usage row (null for Jev-only and
 * failed verdicts).
 */
final readonly class SecondOpinionVerdict
{
    public const string ENGINE_LLM = 'llm';

    public const string ENGINE_JEV = 'jev';

    public function __construct(
        public string $engine,
        public bool $agrees,
        public bool $failed = false,
        public ?float $confidence = null,
        public ?string $reason = null,
        public ?string $provider = null,
        public ?string $model = null,
        public ?ClassificationVerdict $jevVerdict = null,
        public ?float $jevThreshold = null,
        public ?string $invocationId = null,
    ) {}

    public static function failure(string $engine, string $reason): self
    {
        return new self(engine: $engine, agrees: false, failed: true, reason: $reason);
    }

    /**
     * This verdict with the Jev answer that led to it.
     */
    public function withJev(float $probability, ClassificationVerdict $classificationVerdict, float $threshold): self
    {
        return new self(
            engine: $this->engine,
            agrees: $this->agrees,
            failed: $this->failed,
            confidence: $probability,
            reason: $this->reason,
            provider: $this->provider,
            model: $this->model,
            jevVerdict: $classificationVerdict,
            jevThreshold: $threshold,
            invocationId: $this->invocationId,
        );
    }

    /**
     * Whether the action must wait for a human: a disagreement under Hold,
     * or any failed check.
     */
    public function holds(SecondOpinionMode $secondOpinionMode): bool
    {
        return ! $this->agrees && ($this->failed || $secondOpinionMode === SecondOpinionMode::Hold);
    }

    /**
     * Whether the action is rejected outright: a real disagreement under
     * Reject. A failed check never rejects.
     */
    public function rejects(SecondOpinionMode $secondOpinionMode): bool
    {
        return ! $this->agrees && ! $this->failed && $secondOpinionMode === SecondOpinionMode::Reject;
    }

    /**
     * The shape stored on action_requests.second_opinion.
     *
     * @return array{engine: string, mode: string, agrees: bool, failed: bool, confidence: float|null, reason: string|null, provider: string|null, model: string|null, invocation_id: string|null}
     */
    public function toArray(SecondOpinionMode $secondOpinionMode): array
    {
        return [
            'engine' => $this->engine,
            'mode' => $secondOpinionMode->value,
            'agrees' => $this->agrees,
            'failed' => $this->failed,
            'confidence' => $this->confidence,
            'reason' => $this->reason,
            'provider' => $this->provider,
            'model' => $this->model,
            'invocation_id' => $this->invocationId,
        ];
    }
}
