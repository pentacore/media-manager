<?php

declare(strict_types=1);

namespace App\Ai;

use Laravel\Ai\Enums\Lab;

/**
 * The laravel/ai driver behind a configured provider name (an
 * `ai.providers` key). A provider named after its driver, or one that is not
 * configured, is its own driver. Compare drivers, never names, against a
 * provider literal such as `openrouter`, so a custom-named provider
 * (`my-or` on the `openrouter` driver) gets the same request handling.
 */
final class ProviderDriver
{
    public static function of(string $provider): string
    {
        return (string) config(sprintf('ai.providers.%s.driver', $provider), $provider);
    }

    /**
     * Whether the provider runs without an API key (an Ollama driver).
     */
    public static function keyless(string $provider): bool
    {
        return self::of($provider) === Lab::Ollama->value;
    }
}
