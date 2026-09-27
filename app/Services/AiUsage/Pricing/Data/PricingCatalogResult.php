<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing\Data;

use App\Services\AiUsage\Pricing\PricingCatalog;
use App\Services\AiUsage\Pricing\PricingTransportException;

/**
 * The merged, per-provider view of every pricing source for one refresh run,
 * produced by {@see PricingCatalog}.
 */
final readonly class PricingCatalogResult
{
    public const string STATUS_OK = 'ok';

    public const string STATUS_DISABLED = 'disabled';

    /**
     * @param  array<string, ProviderPricingResult>  $providers  Canonical provider => the result chosen by precedence.
     * @param  array<string, string>  $sourceStatuses  Source key => `ok`, `disabled`, or a {@see PricingTransportException} category.
     * @param  bool  $unavailable  True when no source produced data (every enabled source failed, or none was enabled).
     * @param  string|null  $errorMessage  The first transport failure message, excluding `not_configured`.
     */
    public function __construct(
        public array $providers,
        public array $sourceStatuses,
        public bool $unavailable,
        public ?string $errorMessage = null,
    ) {}
}
