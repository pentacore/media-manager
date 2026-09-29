<?php

declare(strict_types=1);

namespace App\Ai;

use App\Models\AiModelPrice;
use Laravel\Ai\Ai;
use Throwable;

/**
 * The providers and catalog models an admin may pick for a model setting:
 * configured providers (an API key is set; Ollama needs none) that laravel/ai
 * can resolve as text providers, and the priced models listed under them.
 */
final readonly class ModelCatalog
{
    /**
     * @return list<string>
     */
    public function textProviders(): array
    {
        return array_values(array_filter(
            $this->configuredProviders(),
            static function (string $provider): bool {
                try {
                    Ai::textProvider($provider);

                    return true;
                } catch (Throwable) {
                    return false;
                }
            },
        ));
    }

    /**
     * Configured providers laravel/ai can generate embeddings with.
     *
     * @return list<string>
     */
    public function embeddingProviders(): array
    {
        return array_values(array_filter(
            $this->configuredProviders(),
            static function (string $provider): bool {
                try {
                    Ai::embeddingProvider($provider);

                    return true;
                } catch (Throwable) {
                    return false;
                }
            },
        ));
    }

    /**
     * @return array<string, list<string>>
     */
    public function modelsByConfiguredProvider(): array
    {
        $providers = $this->textProviders();

        if ($providers === []) {
            return [];
        }

        return AiModelPrice::query()
            ->whereIn('provider', $providers)
            ->orderBy('provider')
            ->orderBy('model')
            ->get(['provider', 'model'])
            ->groupBy('provider')
            ->map(fn ($rows): array => $rows->pluck('model')->values()->all())
            ->all();
    }

    /**
     * @return list<string>
     */
    private function configuredProviders(): array
    {
        /** @var array<string, array<string, mixed>> $providers */
        $providers = config('ai.providers', []);

        return collect($providers)
            ->filter(fn (array $cfg, string $name): bool => $name === 'ollama' || filled($cfg['key'] ?? null))
            ->keys()
            ->values()
            ->all();
    }
}
