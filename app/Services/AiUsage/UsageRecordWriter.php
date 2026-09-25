<?php

declare(strict_types=1);

namespace App\Services\AiUsage;

use App\Models\AiUsageRecord;
use App\Services\AiBudget\AiBudgetGuard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;

/**
 * The one write path for ai_usage_records: agent runs (success and failed),
 * embeddings, reranking and classification all price against the same
 * catalog snapshot and bust the budget guard's cached month total.
 */
final readonly class UsageRecordWriter
{
    public function __construct(
        private ModelPriceLookup $modelPriceLookup,
        private BatchPricingContext $batchPricingContext,
    ) {}

    /**
     * Insert one usage row unless one already exists for its invocation id.
     *
     * @param  array<string, mixed>  $attributes  must include invocation_id, provider, model
     */
    public function record(array $attributes): bool
    {
        // Fast path; the unique index (caught below) closes the concurrent window.
        if (AiUsageRecord::where('invocation_id', $attributes['invocation_id'])->exists()) {
            return false;
        }

        $isBatch = $this->batchPricingContext->enabled;
        $snapshot = $this->modelPriceLookup->snapshotFor($attributes['provider'] ?? null, $attributes['model'] ?? null, $isBatch);

        try {
            AiUsageRecord::create([
                'status' => 'success',
                ...$attributes,
                ...($snapshot ?? []),
                'is_batch' => $isBatch,
                'price_source' => $snapshot === null ? null : 'live',
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent dispatch for the same invocation won the insert race.
            return false;
        }

        // New spend invalidates the budget guard's cached month total.
        Cache::forget(AiBudgetGuard::spendCacheKey());

        return true;
    }
}
