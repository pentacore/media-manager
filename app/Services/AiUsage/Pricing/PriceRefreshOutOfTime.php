<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

use RuntimeException;

/**
 * The queued refresh's time box no longer fits a verifier step. Thrown by
 * PriceVerifierPhase before prompting and by StopWhenPriceRefreshOutOfTime
 * before a later step; the phase's own catch fails the remaining providers,
 * exactly as for a budget stop. Not failoverable: the SDK does not try the
 * next provider.
 */
final class PriceRefreshOutOfTime extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The price refresh ran out of time before the verifier finished; the remaining providers keep their stored prices.');
    }
}
