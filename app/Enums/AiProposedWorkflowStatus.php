<?php

declare(strict_types=1);

namespace App\Enums;

enum AiProposedWorkflowStatus: string
{
    case Proposed = 'proposed';
    case Approved = 'approved';
    case Declined = 'declined';
    case Executed = 'executed';
    case Failed = 'failed';
}
