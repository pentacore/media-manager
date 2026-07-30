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

    public function label(): string
    {
        return match ($this) {
            self::Seed => 'Seed data',
            self::ModelsDev => 'Models.dev',
            self::FirstParty => 'First-party source',
            self::Manual => 'Manual',
            self::Legacy => 'Legacy',
        };
    }
}
