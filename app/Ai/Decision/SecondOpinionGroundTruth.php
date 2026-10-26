<?php

declare(strict_types=1);

namespace App\Ai\Decision;

use App\Enums\ActionRequestStatus;

/**
 * The verdict a person's review says the second opinion should have given:
 * the one ground-truth rule for benchmark capture and the AI Usage agreement
 * panel.
 */
final class SecondOpinionGroundTruth
{
    /**
     * A person's review is the only ground truth: approved_by is set by
     * ActionRequestReviewer for both approve and reject; the verifier's own
     * rejection and an auto-run leave it null.
     *
     * @param  array<string, mixed>|null  $secondOpinion
     */
    public static function expectedAgrees(ActionRequestStatus $status, ?int $approvedBy, ?array $secondOpinion): ?bool
    {
        if ($approvedBy === null || ($secondOpinion['failed'] ?? false) === true) {
            return null;
        }

        return match ($status) {
            ActionRequestStatus::Approved, ActionRequestStatus::Executing, ActionRequestStatus::Completed, ActionRequestStatus::Failed => true,
            ActionRequestStatus::Rejected => false,
            ActionRequestStatus::Pending => null,
        };
    }
}
