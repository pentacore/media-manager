<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ActionRequestStatus;
use App\Events\ActionRequestStatusChanged;
use App\Models\ActionRequest;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Fail action requests stuck in executing past a timeout. A worker killed without running the failed() hook (SIGKILL, host crash, lost Redis job) leaves the row in executing forever, where neither the UI retry (which requires failed) nor any job will ever touch it again.')]
#[Signature('actions:reconcile-stuck {--hours=2 : Fail executing action requests last updated more than this many hours ago}')]
class ReconcileStuckActionRequests extends Command
{
    public function handle(): int
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
                        'reason' => 'worker_lost',
                        'message' => sprintf('Execution never completed after %d hour(s); the worker likely died. Review the target service before retrying — the upstream effect may or may not have happened.', $hours),
                    ]),
                ]);

            if ($affected !== 1) {
                continue;
            }

            $failed++;
            event(new ActionRequestStatusChanged($actionRequest->refresh()));
        }

        $this->info(sprintf('Failed %d action request(s) stuck in executing.', $failed));

        return self::SUCCESS;
    }
}
