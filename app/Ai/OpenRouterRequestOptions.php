<?php

declare(strict_types=1);

namespace App\Ai;

use App\Enums\AiReasoningLevel;
use App\Settings\OpenRouterSettings;
use Laravel\Ai\Enums\Lab;

/**
 * Request body keys every agent adds when it talks to OpenRouter: the unified
 * `reasoning.effort` parameter and the `provider` routing object. OpenRouter
 * accepts efforts `none` through `xhigh`, so `max` maps to `xhigh`.
 */
final readonly class OpenRouterRequestOptions
{
    public function __construct(private OpenRouterSettings $openRouterSettings) {}

    /**
     * The OpenRouter options for an agent's providerOptions() call, or an
     * empty array for any other provider.
     *
     * @return array<string, mixed>
     */
    public function for(Lab|string $provider, ?string $reasoningLevel = null): array
    {
        $value = $provider instanceof Lab ? $provider->value : $provider;

        if ($value !== Lab::OpenRouter->value) {
            return [];
        }

        return [...$this->reasoning($reasoningLevel), ...$this->routing()];
    }

    /**
     * @return array{reasoning?: array{effort: string}}
     */
    public function reasoning(?string $reasoningLevel): array
    {
        if ($reasoningLevel === null || $reasoningLevel === '') {
            return [];
        }

        $effort = $reasoningLevel === AiReasoningLevel::Max->value ? AiReasoningLevel::XHigh->value : $reasoningLevel;

        return ['reasoning' => ['effort' => $effort]];
    }

    /**
     * The `provider` routing object, holding only values that differ from
     * OpenRouter's defaults; empty when nothing is configured.
     *
     * @return array{provider?: array<string, mixed>}
     */
    public function routing(): array
    {
        $routing = array_filter([
            'sort' => $this->openRouterSettings->sort()?->value,
            'data_collection' => $this->openRouterSettings->denyDataCollection() ? 'deny' : null,
            'allow_fallbacks' => $this->openRouterSettings->allowFallbacks() ? null : false,
            'order' => $this->openRouterSettings->order() ?: null,
            'ignore' => $this->openRouterSettings->ignore() ?: null,
        ], static fn (mixed $value): bool => $value !== null);

        return $routing === [] ? [] : ['provider' => $routing];
    }
}
