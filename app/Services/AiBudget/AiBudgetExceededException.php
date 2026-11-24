<?php

declare(strict_types=1);

namespace App\Services\AiBudget;

use RuntimeException;

class AiBudgetExceededException extends RuntimeException
{
    /** How every refusal message starts, so a stored one can be recognised. */
    public const string MESSAGE_PREFIX = 'Monthly AI hard cap reached';

    public function __construct(
        public readonly float $spendUsd,
        public readonly float $hardCapUsd,
    ) {
        parent::__construct(sprintf(
            '%s (spend $%0.2f / cap $%0.2f).',
            self::MESSAGE_PREFIX,
            $spendUsd,
            $hardCapUsd,
        ));
    }
}
