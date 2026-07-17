<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Tools\Bazarr\InspectSubtitleEscalationTool;
use App\Ai\Tools\Bazarr\QueueAutomaticReplacementTool;
use App\Settings\AiSettings;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;

#[MaxSteps(4)]
final class SubtitleAdvisorAgent implements Agent, HasTools
{
    use Promptable;

    public function model(): string
    {
        return resolve(AiSettings::class)->model();
    }

    public function instructions(): string
    {
        return <<<'PROMPT'
You investigate exactly one subtitle replacement escalation. Call the inspection tool once.
You may queue a replacement only when the inspection returns a non-null automatic_candidate.
Use exactly that fingerprint; never select another candidate, infer missing identifiers, or bypass a safety gate.
If there is no automatic candidate, explain why the case needs human review and queue nothing.
End with a concise audit summary; never claim replacement is complete.
PROMPT;
    }

    /**
     * @return iterable<int, Tool>
     */
    public function tools(): iterable
    {
        return [
            resolve(InspectSubtitleEscalationTool::class),
            resolve(QueueAutomaticReplacementTool::class),
        ];
    }
}
