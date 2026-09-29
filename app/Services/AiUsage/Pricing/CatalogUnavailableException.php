<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

use App\Services\AiUsage\Pricing\Data\PricingCatalogResult;
use RuntimeException;

/**
 * Raised when the catalog picker cannot load a provider's slice: every
 * enabled source failed or none is enabled, a feed that could have priced the
 * provider failed, or another request's download is still running.
 */
final class CatalogUnavailableException extends RuntimeException
{
    public static function fromResult(PricingCatalogResult $pricingCatalogResult): self
    {
        return new self($pricingCatalogResult->errorMessage ?? __('No pricing source is enabled or reachable.'));
    }
}
