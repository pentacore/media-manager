<?php

declare(strict_types=1);

namespace App\Ai;

use App\Settings\OpenRouterSettings;
use Laravel\Ai\Enums\Lab;

/**
 * Request body keys every OpenRouter call adds: the `provider` routing
 * object. Reasoning is mapped per provider by ReasoningOptions.
 */
final readonly class OpenRouterRequestOptions
{
    public function __construct(private OpenRouterSettings $openRouterSettings) {}

    /**
     * The OpenRouter routing options for a providerOptions() callback, or an
     * empty array for any other provider.
     *
     * @return array{provider?: array<string, mixed>}
     */
    public function for(Lab|string $provider): array
    {
        $value = $provider instanceof Lab ? $provider->value : $provider;

        return $value === Lab::OpenRouter->value ? $this->routing() : [];
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
