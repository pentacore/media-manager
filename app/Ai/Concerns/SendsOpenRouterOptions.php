<?php

declare(strict_types=1);

namespace App\Ai\Concerns;

use App\Ai\OpenRouterRequestOptions;
use Laravel\Ai\Enums\Lab;

/**
 * providerOptions() for an agent with no provider-specific options of its
 * own: OpenRouter routing preferences, nothing for any other provider.
 */
trait SendsOpenRouterOptions
{
    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        return resolve(OpenRouterRequestOptions::class)->for($provider);
    }
}
