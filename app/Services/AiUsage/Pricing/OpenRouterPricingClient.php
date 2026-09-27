<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

/**
 * Transport for OpenRouter's public, unauthenticated models API, the
 * authoritative price list for model ids routed through OpenRouter.
 */
final readonly class OpenRouterPricingClient
{
    public function __construct(
        private PricingFeedFetcher $pricingFeedFetcher,
    ) {}

    /**
     * @return list<mixed> The response's `data` list of model entries.
     *
     * @throws PricingTransportException
     */
    public function fetch(): array
    {
        $decoded = $this->pricingFeedFetcher->fetch('openrouter', 'OpenRouter', class_basename($this));

        $models = is_array($decoded) ? ($decoded['data'] ?? null) : null;

        if (! is_array($models) || ! array_is_list($models)) {
            throw PricingTransportException::invalidShape('OpenRouter', 'an object with a data list');
        }

        return $models;
    }
}
