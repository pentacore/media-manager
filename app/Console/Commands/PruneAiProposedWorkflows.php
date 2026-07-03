<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AiProposedWorkflowStatus;
use App\Models\AiProposedWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Decline stale Proposed workflows then prune terminal-status rows beyond the retention window.')]
#[Signature('ai:prune-proposed-workflows
                            {--days=30 : Delete terminal-status workflows older than this many days}
                            {--stale-days=7 : Decline still-Proposed workflows older than this many days}')]
class PruneAiProposedWorkflows extends Command
{
    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $staleDays = max(1, (int) $this->option('stale-days'));
        $now = CarbonImmutable::now();

        $declined = AiProposedWorkflow::where('status', AiProposedWorkflowStatus::Proposed)
            ->where('created_at', '<', $now->subDays($staleDays))
            ->update(['status' => AiProposedWorkflowStatus::Declined]);

        $deleted = AiProposedWorkflow::whereIn('status', [
            AiProposedWorkflowStatus::Approved,
            AiProposedWorkflowStatus::Declined,
            AiProposedWorkflowStatus::Executed,
            AiProposedWorkflowStatus::Failed,
        ])
            ->where('updated_at', '<', $now->subDays($days))
            ->delete();

        $this->info(sprintf('Auto-declined %d stale workflow(s); pruned %d terminal-status workflow(s).', $declined, $deleted));

        return self::SUCCESS;
    }
}
