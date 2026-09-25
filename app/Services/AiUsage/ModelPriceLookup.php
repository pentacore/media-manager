<?php

declare(strict_types=1);

namespace App\Services\AiUsage;

use App\Models\AiModelPrice;

/**
 * Resolves the catalog rates a usage row is priced against and computes the
 * USD cost of a set of usage columns under those rates. Shared by every
 * billable kind so agent runs, embeddings, reranking and classification all
 * price the same way.
 */
final class ModelPriceLookup
{
    /**
     * Snapshot the catalog rates for a usage row. When the run is
     * batch-flagged, each token tier prefers its `batch_*_per_mtok` rate,
     * falling back to the standard rate when the batch column is unset or
     * zero. Snapshotting the batch rates into the standard snapshot keys
     * keeps downstream reporting unchanged.
     *
     * @return array{input_per_mtok: string, output_per_mtok: string, cache_read_per_mtok: string, cache_write_per_mtok: string, reasoning_per_mtok: string}|null
     */
    public function snapshotFor(?string $provider, ?string $model, bool $isBatch): ?array
    {
        if ($provider === null || $provider === '' || $model === null || $model === '') {
            return null;
        }

        // Strip a trailing dated suffix (e.g. "-2025-09-23") so a snapshot
        // recorded against the base model id still resolves for dated
        // variants. Mirrors the JOIN logic in AiUsageReporting.
        $baseModel = preg_replace('/-\d{4}-\d{2}-\d{2}$/', '', $model);

        $price = AiModelPrice::query()
            ->where('provider', $provider)
            ->where('model', $baseModel)
            ->first();

        if (! $price instanceof AiModelPrice) {
            return null;
        }

        return [
            'input_per_mtok' => $this->rateFor($price->input_per_mtok, $price->batch_input_per_mtok, $isBatch),
            'output_per_mtok' => $this->rateFor($price->output_per_mtok, $price->batch_output_per_mtok, $isBatch),
            'cache_read_per_mtok' => $this->rateFor($price->cache_read_per_mtok, $price->batch_cache_read_per_mtok, $isBatch),
            'cache_write_per_mtok' => $this->rateFor($price->cache_write_per_mtok, $price->batch_cache_write_per_mtok, $isBatch),
            'reasoning_per_mtok' => $this->rateFor($price->reasoning_per_mtok, $price->batch_reasoning_per_mtok, $isBatch),
        ];
    }

    /**
     * USD cost of one row's token columns under a snapshot from snapshotFor().
     *
     * @param  array<string, int|float|string>  $columns
     * @param  array<string, string>  $snapshot
     */
    public function costOf(array $columns, array $snapshot): float
    {
        $tokens = (float) ($columns['prompt_tokens'] ?? 0) * (float) $snapshot['input_per_mtok']
            + (float) ($columns['completion_tokens'] ?? 0) * (float) $snapshot['output_per_mtok']
            + (float) ($columns['cache_read_input_tokens'] ?? 0) * (float) $snapshot['cache_read_per_mtok']
            + (float) ($columns['cache_write_input_tokens'] ?? 0) * (float) $snapshot['cache_write_per_mtok']
            + (float) ($columns['reasoning_tokens'] ?? 0) * (float) $snapshot['reasoning_per_mtok'];

        $searchUnits = (float) ($columns['search_units'] ?? 0) * (float) ($snapshot['search_unit_per_k'] ?? 0) / 1000;

        return round($tokens / 1_000_000 + $searchUnits, 6);
    }

    /**
     * Pick the batch rate for a token tier when the run is batch-flagged and
     * the batch rate is set and positive; otherwise use the standard rate.
     */
    private function rateFor(string $standard, ?string $batch, bool $isBatch): string
    {
        if ($isBatch && $batch !== null && (float) $batch > 0.0) {
            return $batch;
        }

        return $standard;
    }
}
