<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

/**
 * Who settled a classification outcome: the event the gate waited for, the
 * second-opinion cascade's LLM, or a human reviewer.
 */
enum ClassificationOutcomeSource: string
{
    use EnumUtils;

    case Event = 'event';
    case Llm = 'llm';
    case Reviewer = 'reviewer';

    public function label(): string
    {
        return match ($this) {
            self::Event => 'Observed event',
            self::Llm => 'Second opinion',
            self::Reviewer => 'Reviewer',
        };
    }
}
