<?php

declare(strict_types=1);

use App\Models\AiModelPrice;
use App\Models\AiUsageRecord;
use App\Services\AiUsage\AiUsageReporting;
use App\Services\AiUsage\Scenario;

test('reranking rows add search-unit cost to totals', function (): void {
    AiUsageRecord::factory()->reranking()->create([
        'prompt_tokens' => 0, 'completion_tokens' => 0, 'cache_read_input_tokens' => 0, 'cache_write_input_tokens' => 0, 'reasoning_tokens' => 0,
        'search_units' => 500, 'search_unit_per_k' => 2.0, 'input_per_mtok' => 0, 'output_per_mtok' => 0,
    ]);

    expect((float) resolve(AiUsageReporting::class)->totals(null)['total_cost'])->toBe(1.0);
});

test('unsnapshotted reranking rows fall back to the catalog search-unit rate', function (): void {
    AiModelPrice::factory()->create(['provider' => 'cohere', 'model' => 'rerank-v3.5', 'input_per_mtok' => 0, 'output_per_mtok' => 0, 'search_unit_per_k' => 4.0]);
    AiUsageRecord::factory()->reranking()->create([
        'provider' => 'cohere', 'model' => 'rerank-v3.5',
        'prompt_tokens' => 0, 'completion_tokens' => 0, 'search_units' => 250,
    ]);

    expect((float) resolve(AiUsageReporting::class)->totals(null)['total_cost'])->toBe(1.0);
});

test('invocation detail breaks out search-unit cost', function (): void {
    $aiUsageRecord = AiUsageRecord::factory()->reranking()->create([
        'prompt_tokens' => 0, 'completion_tokens' => 0, 'search_units' => 500, 'search_unit_per_k' => 2.0, 'input_per_mtok' => 0, 'output_per_mtok' => 0,
    ]);

    $detail = resolve(AiUsageReporting::class)->invocationDetail($aiUsageRecord);
    $searchRow = collect($detail['breakdown'])->firstWhere('label', 'Search units');

    expect($searchRow['cost'])->toBe(1.0)
        ->and($detail['rates']['search_unit_per_k'])->toBe(2.0)
        ->and($detail['total_cost'])->toBe(1.0)
        ->and($detail['record']['kind'])->toBe('reranking')
        ->and($detail['record']['search_units'])->toBe(500.0);
});

test('scenarios keep the snapshotted search-unit rate', function (): void {
    AiUsageRecord::factory()->reranking()->create([
        'prompt_tokens' => 0, 'completion_tokens' => 0, 'cache_read_input_tokens' => 0, 'cache_write_input_tokens' => 0, 'reasoning_tokens' => 0,
        'search_units' => 500, 'search_unit_per_k' => 2.0,
    ]);

    $scenario = new Scenario(inputPerMtok: 1.0, outputPerMtok: 1.0, cacheReadPerMtok: 0.0, cacheWritePerMtok: 0.0, reasoningPerMtok: 0.0);

    expect((float) resolve(AiUsageReporting::class)->totals(null, $scenario)['total_cost'])->toBe(1.0);
});
