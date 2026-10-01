<?php

declare(strict_types=1);

namespace App\Services\Actions;

use App\Enums\ActionRequestStatus;
use App\Events\ActionRequestStatusChanged;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Approves or rejects one pending Action Queue request. The single buttons
 * and the bulk endpoint both come through here, so the row lock, the "still
 * pending" check, the broadcast and the execution dispatch are identical.
 */
final readonly class ActionRequestReviewer
{
    /**
     * @return bool false when the request is no longer pending
     */
    public function approve(ActionRequest $actionRequest, User $user): bool
    {
        return DB::transaction(function () use ($actionRequest, $user): bool {
            $lockedActionRequest = ActionRequest::query()
                ->whereKey($actionRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedActionRequest->status !== ActionRequestStatus::Pending) {
                return false;
            }

            $lockedActionRequest->update([
                'status' => ActionRequestStatus::Approved,
                'approved_by' => $user->id,
            ]);
            // Broadcast only after the transaction commits: firing inside it
            // announced state that could still roll back, and a fast client
            // partial-reload could read pre-commit data.
            DB::afterCommit(static fn () => event(new ActionRequestStatusChanged($lockedActionRequest)));
            dispatch(new ExecuteActionRequest($lockedActionRequest))->afterCommit();

            return true;
        });
    }

    /**
     * @return bool false when the request is no longer pending
     */
    public function reject(ActionRequest $actionRequest, User $user, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($actionRequest, $user, $reason): bool {
            $lockedActionRequest = ActionRequest::query()
                ->whereKey($actionRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedActionRequest->status !== ActionRequestStatus::Pending) {
                return false;
            }

            $lockedActionRequest->update([
                'status' => ActionRequestStatus::Rejected,
                'approved_by' => $user->id,
                ...($reason === null ? [] : ['result' => ['rejection_reason' => $reason]]),
            ]);
            DB::afterCommit(static fn () => event(new ActionRequestStatusChanged($lockedActionRequest)));

            return true;
        });
    }
}
