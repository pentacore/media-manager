<?php

declare(strict_types=1);

namespace App\Enums;

use App\Concerns\EnumUtils;

enum AgentDecisionStatus: string
{
    use EnumUtils;

    /** The agent ran and concluded no action was warranted. */
    case NoAction = 'no_action';

    /** The agent ran and proposed one or more actions. */
    case Completed = 'completed';

    /** The agent run itself errored (LLM/tool failure, budget, etc.). */
    case Failed = 'failed';

    /** Classification judged the event unlikely to need action; the agent did not run. */
    case SkippedByGate = 'skipped_by_gate';

    public function label(): string
    {
        return match ($this) {
            self::NoAction => 'No action',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::SkippedByGate => 'Skipped by gate',
        };
    }
}
