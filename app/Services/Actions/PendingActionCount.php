<?php

declare(strict_types=1);

namespace App\Services\Actions;

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use Illuminate\Support\Facades\DB;

/**
 * The sidebar's pending-actions badge: every pending request, and the
 * newest status_version that count reflects, read in one statement so both
 * describe the same snapshot. Broadcasts counted by parallel queue workers
 * can land out of order, and history navigation restores an old `nav`; the
 * badge keeps the count with the higher version (useNavCounts).
 *
 * The statement is two scalar subqueries, so each reads its own index
 * (`status`, `status_version`) instead of scanning every row.
 */
final readonly class PendingActionCount
{
    /**
     * @return array{count: int, version: int}
     */
    public function snapshot(): array
    {
        $row = DB::query()
            ->selectSub(
                ActionRequest::query()->where('status', ActionRequestStatus::Pending->value)->selectRaw('count(*)'),
                'pending',
            )
            ->selectSub(
                ActionRequest::query()->selectRaw('coalesce(max(status_version), 0)'),
                'version',
            )
            ->first();

        return [
            'count' => (int) ($row->pending ?? 0),
            'version' => (int) ($row->version ?? 0),
        ];
    }
}
