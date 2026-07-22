<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing\Data;

/**
 * The immutable, provider-scoped outcome of adapting one canonical provider's
 * slice of a source catalog into pricing candidates.
 *
 * Produced by a pure source adapter (no Eloquent or database access) and keyed
 * by canonical Laravel AI provider identity. The refresh coordinator persists
 * {@see $candidates} through the single writer and folds {@see $rejections} and
 * {@see $warnings} into the run audit.
 */
final readonly class ProviderPricingResult
{
    /**
     * @param  list<ModelPriceCandidate>  $candidates  Writable candidates for this provider.
     * @param  list<PricingRejection>  $rejections  Models the source described but the adapter could not safely accept.
     * @param  list<PricingWarning>  $warnings  Non-fatal notes attached to accepted candidates (for example context tiers).
     * @param  bool  $createSuppressed  When true, these candidates may only update existing rows and must never create new ones.
     *                                  The pure adapter cannot query the catalog, so it flags OpenRouter results here rather than
     *                                  consulting the database; the writer still enforces the rule via scope.
     */
    public function __construct(
        public string $provider,
        public array $candidates,
        public array $rejections = [],
        public array $warnings = [],
        public bool $createSuppressed = false,
    ) {}
}
