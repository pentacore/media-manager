<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum FreePoolOverflowBehavior: string
{
    use EnumUtils;

    /**
     * A request draws from the pool only when it fits the remaining quota
     * in full; otherwise the entire request is billed at paid rates and
     * the pool is left untouched (OpenAI-style accounting).
     */
    case FitOrPaid = 'fit_or_paid';

    /**
     * Tokens up to the cap are free and only the overage is billed, for
     * providers that keep serving paid requests once the free tier runs out.
     */
    case Split = 'split';

    public function label(): string
    {
        return match ($this) {
            self::FitOrPaid => 'Entire request must fit (OpenAI-style)',
            self::Split => 'Split — overage billed as paid',
        };
    }
}
