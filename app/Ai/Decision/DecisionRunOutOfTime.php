<?php

declare(strict_types=1);

namespace App\Ai\Decision;

use RuntimeException;

/**
 * A decision run reached its deadline (the admin's run time limit) before
 * its next DecisionAgent step. RunDecisionAgent records a Failed decision
 * with this message.
 */
final class DecisionRunOutOfTime extends RuntimeException
{
    public static function beforeStep(int $step, int $runTimeLimit): self
    {
        return new self(sprintf('The decision run reached its time limit (%d s) before step %d.', $runTimeLimit, $step + 1));
    }
}
