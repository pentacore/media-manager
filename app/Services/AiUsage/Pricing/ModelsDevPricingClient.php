<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

/**
 * Transport for the public Models.dev pricing catalog.
 *
 * Fetches `mediamanager.ai.pricing.models_dev.url` via {@see PricingFeedFetcher}
 * and returns the decoded top-level provider map, or raises a classified
 * {@see PricingTransportException}. This client is transport-only: it does no
 * DB work and does not interpret provider/model shapes beyond the top-level
 * check below — that is the adapter's job.
 */
final class ModelsDevPricingClient
{
    public function __construct(
        private readonly PricingFeedFetcher $pricingFeedFetcher,
    ) {}

    /**
     * Fetch and decode the Models.dev pricing catalog.
     *
     * @return array<string, mixed> Top-level provider map keyed by upstream provider id.
     *
     * @throws PricingTransportException
     */
    public function fetch(): array
    {
        $decoded = $this->pricingFeedFetcher->fetch('models_dev', 'Models.dev', class_basename($this));

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw PricingTransportException::invalidShape('Models.dev', 'a top-level provider object');
        }

        return $decoded;
    }
}
