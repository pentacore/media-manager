<?php

declare(strict_types=1);

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Models\SubtitleAuditBatch;
use App\Services\Chat\SubtitleAuditBatchCard;
use Illuminate\Http\JsonResponse;

/**
 * A subtitle audit batch's chat card as the database has it now. The chat
 * re-reads it after a partial bulk review, whose summary only counts.
 */
class SubtitleAuditBatchController extends Controller
{
    public function __invoke(SubtitleAuditBatch $subtitleAuditBatch): JsonResponse
    {
        return response()->json(SubtitleAuditBatchCard::present($subtitleAuditBatch));
    }
}
