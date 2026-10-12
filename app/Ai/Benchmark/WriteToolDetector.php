<?php

declare(strict_types=1);

namespace App\Ai\Benchmark;

use App\Ai\Risk;
use App\Ai\Tools\BaseTool;
use App\Ai\Tools\Decision\DecisionTool;
use Laravel\Ai\Contracts\Tool;

/**
 * Whether a tool changes anything when it runs: such a call is captured as an
 * intended action in a benchmark instead of being answered as a lookup.
 * Sub-agents (AgentTool) and every other tool are read-only.
 */
final class WriteToolDetector
{
    public static function writes(Tool $tool): bool
    {
        return match (true) {
            $tool instanceof BaseTool => $tool->risk() !== Risk::Read,
            $tool instanceof DecisionTool => $tool->mutates(),
            default => false,
        };
    }
}
