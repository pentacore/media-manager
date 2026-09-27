<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

/**
 * Transport for xAI's first-party pricing endpoint (`GET /v1/language-models`),
 * authenticated with the xAI provider key.
 */
final readonly class XaiPricingClient
{
    public function __construct(
        private PricingFeedFetcher $pricingFeedFetcher,
    ) {}

    /**
     * @return list<mixed> The response's `models` list.
     *
     * @throws PricingTransportException
     */
    public function fetch(): array
    {
        $key = config('ai.providers.xai.key');

        if (! is_string($key) || $key === '') {
            throw PricingTransportException::notConfigured('xAI');
        }

        $decoded = $this->pricingFeedFetcher->fetch(
            'xai',
            'xAI',
            class_basename($this),
            ['Authorization' => sprintf('Bearer %s', $key)],
        );

        $models = is_array($decoded) ? ($decoded['models'] ?? null) : null;

        if (! is_array($models) || ! array_is_list($models)) {
            throw PricingTransportException::invalidShape('xAI', 'an object with a models list');
        }

        return $models;
    }
}
