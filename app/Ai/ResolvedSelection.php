<?php

declare(strict_types=1);

namespace App\Ai;

use App\Enums\AiReasoningLevel;

/**
 * The provider, model and reasoning level a task runs on after every
 * override and fallback is applied.
 */
final readonly class ResolvedSelection
{
    public function __construct(
        public string $provider,
        public string $model,
        public AiReasoningLevel $reasoning,
    ) {}

    public function modelSelection(): ModelSelection
    {
        return new ModelSelection($this->provider, $this->model);
    }
}
