<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

/**
 * Transport for LiteLLM's community-maintained model price map, used to
 * cross-check models.dev for direct providers.
 */
final readonly class LiteLlmPricingClient
{
    public function __construct(
        private PricingFeedFetcher $pricingFeedFetcher,
    ) {}

    /**
     * @return array<string, mixed> Model entries keyed by LiteLLM model key.
     *
     * @throws PricingTransportException
     */
    public function fetch(): array
    {
        $decoded = $this->pricingFeedFetcher->fetch('litellm', 'LiteLLM', class_basename($this));

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw PricingTransportException::invalidShape('LiteLLM', 'a top-level model object');
        }

        return $decoded;
    }
}
