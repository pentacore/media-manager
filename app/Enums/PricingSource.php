<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

/**
 * Records the origin of stored AI model pricing for synchronization,
 * precedence, and audit decisions.
 */
enum PricingSource: string
{
    use EnumUtils;

    /**
     * Pricing supplied by the application's maintained seed data.
     */
    case Seed = 'seed';

    /**
     * Pricing synchronized from the Models.dev catalog.
     */
    case ModelsDev = 'models_dev';

    /**
     * Pricing sourced directly from a provider's first-party documentation.
     */
    case FirstParty = 'first_party';

    /**
     * Pricing entered or edited manually by an administrator.
     */
    case Manual = 'manual';

    /**
     * A historical price row that predates provenance tracking.
     */
    case Legacy = 'legacy';

    /**
     * Pricing synchronized from OpenRouter's public models API (the price
     * OpenRouter charges for its own routed model ids).
     */
    case OpenRouter = 'openrouter';

    /**
     * Pricing taken from the LiteLLM community price map when models.dev had
     * no entry for the model.
     */
    case LiteLlm = 'litellm';

    /**
     * Pricing read from xAI's first-party pricing API.
     */
    case XaiApi = 'xai_api';

    /**
     * Pricing confirmed by models.dev and LiteLLM agreeing on the primary rates.
     */
    case FeedConsensus = 'feed_consensus';

    public function label(): string
    {
        return match ($this) {
            self::Seed => 'Seed data',
            self::ModelsDev => 'Models.dev',
            self::FirstParty => 'First-party source',
            self::Manual => 'Manual',
            self::Legacy => 'Legacy',
            self::OpenRouter => 'OpenRouter',
            self::LiteLlm => 'LiteLLM',
            self::XaiApi => 'xAI API',
            self::FeedConsensus => 'Models.dev + LiteLLM',
        };
    }

    /**
     * Whether values from this source come straight from the provider's own
     * pricing API, making them verification-grade: the write stamps
     * `pricing_verified_at` and may bypass the anomaly guard.
     */
    public function isFirstPartyApi(): bool
    {
        return $this === self::XaiApi;
    }
}
