<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ActionRequestStatus;
use App\Events\ActionRequestStatusChanged;
use App\Models\ActionRequest;
use App\Services\Actions\ActionRequestActivityLogger;
use App\Services\Actions\StaleApprovedRequestRedispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Fail action requests stuck in executing past a timeout, and re-dispatch approved requests whose execution job was lost. A worker killed without running the failed() hook (SIGKILL, host crash, lost Redis job) leaves the row in executing forever, and a lost job leaves an approved row waiting forever; neither the UI retry (which requires failed) nor any job would touch them again.')]
#[Signature('actions:reconcile-stuck {--hours=2 : Fail executing action requests last updated more than this many hours ago} {--approved-minutes=30 : Re-dispatch approved action requests last updated more than this many minutes ago}')]
class ReconcileStuckActionRequests extends Command
{
    public function handle(ActionRequestActivityLogger $actionRequestActivityLogger, StaleApprovedRequestRedispatcher $staleApprovedRequestRedispatcher): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $cutoff = CarbonImmutable::now()->subHours($hours);

        $stuck = ActionRequest::query()
            ->where('status', ActionRequestStatus::Executing->value)
            ->where('updated_at', '<', $cutoff)
            ->get();

        $failed = 0;

        foreach ($stuck as $actionRequest) {
            // Conditional transition: a live (just very slow) worker may
            // complete between selection and here — never regress its result.
            $affected = ActionRequest::query()
                ->whereKey($actionRequest->id)
                ->where('status', ActionRequestStatus::Executing->value)
                ->where('updated_at', '<', $cutoff)
                ->update([
                    'status' => ActionRequestStatus::Failed->value,
                    'result' => json_encode([
                        'success' => false,
                        'reason' => 'needs_reconciliation',
                        'message' => sprintf('Execution never completed after %d hour(s); the worker likely died. Review the target service before retrying — the upstream effect may or may not have happened.', $hours),
                        'indeterminate' => true,
                        'worker_lost' => true,
                    ]),
                ]);

            if ($affected !== 1) {
                continue;
            }

            $failed++;
            $actionRequest->refresh();
            // The conditional update bypasses ActionRequestObserver.
            $actionRequestActivityLogger->statusChanged($actionRequest);
            event(new ActionRequestStatusChanged($actionRequest));
        }

        $this->info(sprintf('Failed %d action request(s) stuck in executing.', $failed));

        $approvedMinutes = max(5, (int) $this->option('approved-minutes'));
        $redispatched = $staleApprovedRequestRedispatcher->redispatch(CarbonImmutable::now()->subMinutes($approvedMinutes));

        $this->info(sprintf('Re-dispatched %d approved action request(s) that never started.', $redispatched));

        return self::SUCCESS;
    }
}
