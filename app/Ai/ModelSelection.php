<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * A provider + model pair an AI workload runs on. The provider is a
 * `config('ai.providers')` key (a laravel/ai `Lab` value); the model is the
 * identifier that provider's API expects (e.g. `anthropic/claude-sonnet-5` on
 * OpenRouter, `claude-sonnet-5` on Anthropic directly).
 */
final readonly class ModelSelection
{
    public function __construct(
        public string $provider,
        public string $model,
    ) {}

    /**
     * @return array{provider: string, model: string}
     */
    public function toArray(): array
    {
        return ['provider' => $this->provider, 'model' => $this->model];
    }
}
