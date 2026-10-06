<?php

declare(strict_types=1);

namespace App\Services\AiUsage\Pricing;

use RuntimeException;

/**
 * The queued refresh's time box no longer fits a verifier step. Thrown by
 * PriceVerifierPhase before prompting and by StopWhenPriceRefreshOutOfTime
 * before a later step — including a failover's restarted step 0, which runs
 * on the next provider at step 0 again and so is checked like any other
 * step. The phase's own catch folds in whatever the agent already verified
 * and wrote before the stop, so only providers it never got to fail; this
 * message is generic because the catch reports per-provider status
 * separately. Not failoverable: the SDK does not try the next provider.
 */
final class PriceRefreshOutOfTime extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The price refresh ran out of time before the verifier finished; providers it had not verified keep their stored prices.');
    }
}
