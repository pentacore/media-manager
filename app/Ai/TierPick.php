<?php

declare(strict_types=1);

namespace App\Ai;

use App\Enums\AiReasoningLevel;

/**
 * One list walk's result inside TaskModelResolver: the pair, the reasoning
 * the list chose (null = the task's own fallback chain decides) and the tier
 * outcome to report.
 */
final readonly class TierPick
{
    public function __construct(
        public string $provider,
        public string $model,
        public ?AiReasoningLevel $reasoning = null,
        public ?TierOutcome $tier = null,
    ) {}

    /**
     * The same pair and tier without the list's reasoning — for tasks that
     * follow another task's list (they never take its reasoning).
     */
    public function pairOnly(?string $via = null): self
    {
        return new self($this->provider, $this->model, null, $via === null ? $this->tier : $this->tier?->via($via));
    }

    /**
     * The same pick with its tier outcome labelled as coming from another list.
     */
    public function via(string $label): self
    {
        return new self($this->provider, $this->model, $this->reasoning, $this->tier?->via($label));
    }
}
