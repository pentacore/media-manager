<?php

declare(strict_types=1);

namespace App\Services\AiBudget;

use RuntimeException;

class AiBudgetExceededException extends RuntimeException
{
    public function __construct(
        public readonly float $spendUsd,
        public readonly float $hardCapUsd,
    ) {
        parent::__construct(sprintf(
            'Monthly AI hard cap reached (spend $%0.2f / cap $%0.2f).',
            $spendUsd,
            $hardCapUsd,
        ));
    }
}
