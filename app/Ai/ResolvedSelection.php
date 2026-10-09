<?php

declare(strict_types=1);

namespace App\Ai;

use App\Enums\AiReasoningLevel;

/**
 * The provider, model and reasoning level a task runs on after every
 * override, fallback and tier is applied; `tier` says which tier of the task's
 * list ran.
 */
final readonly class ResolvedSelection
{
    public function __construct(
        public string $provider,
        public string $model,
        public AiReasoningLevel $reasoning,
        public ?TierOutcome $tier = null,
    ) {}

    public function modelSelection(): ModelSelection
    {
        return new ModelSelection($this->provider, $this->model);
    }
}
