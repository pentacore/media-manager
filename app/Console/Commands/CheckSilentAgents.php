<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\HostMonitoring\Rules\AgentSilentRule;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Open an incident for every enrolled server whose agent stopped reporting.')]
#[Signature('servers:check-silent')]
class CheckSilentAgents extends Command
{
    public function handle(AgentSilentRule $agentSilentRule): int
    {
        $this->info(sprintf('%d silent agent incident(s) raised or refreshed.', $agentSilentRule->sweep()));

        return self::SUCCESS;
    }
}
