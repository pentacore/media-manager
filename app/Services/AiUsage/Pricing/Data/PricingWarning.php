<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing\Data;

/**
 * A non-fatal note attached to a pricing candidate that was still accepted.
 *
 * The primary use is signalling context-tiered pricing: the base rate is kept
 * as the candidate value (never flattened to a tier), and this warning records
 * that the upstream model prices differently above a context threshold so the
 * run audit and UI can surface the caveat.
 */
final readonly class PricingWarning
{
    /**
     * The model prices differently above one or more context-size thresholds
     * (a `tiers` array and/or a `context_over_*` key). The base rate is retained
     * unflattened; {@see self::$detail} names the detected tier signals.
     */
    public const string CONTEXT_TIERS = 'context_tiers';

    public function __construct(
        public string $provider,
        public string $model,
        public string $code,
        public ?string $detail = null,
    ) {}
}
