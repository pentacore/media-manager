<?php

declare(strict_types=1);

use App\Enums\AiUsageKind;
use App\Models\AiModelPrice;
use App\Models\AiUsageRecord;
use App\Services\AiBudget\AiBudgetGuard;
use App\Services\AiUsage\ModelPriceLookup;
use App\Services\AiUsage\UsageRecordWriter;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'text-embedding-3-small', 'input_per_mtok' => 0.02, 'output_per_mtok' => 0]);
});

test('the writer snapshots catalog rates and dedupes by invocation id', function (): void {
    Cache::put(AiBudgetGuard::spendCacheKey(), 1.0);
    $writer = resolve(UsageRecordWriter::class);

    $attributes = ['invocation_id' => 'emb-1', 'kind' => AiUsageKind::Embeddings, 'agent_class' => 'LibraryEmbedder', 'provider' => 'openai', 'model' => 'text-embedding-3-small', 'prompt_tokens' => 1_000_000];

    expect($writer->record($attributes))->toBeTrue()
        ->and($writer->record($attributes))->toBeFalse();

    $row = AiUsageRecord::where('invocation_id', 'emb-1')->sole();

    expect($row->input_per_mtok)->toBe('0.0200')
        ->and($row->price_source)->toBe('live')
        ->and($row->kind)->toBe(AiUsageKind::Embeddings)
        ->and(Cache::has(AiBudgetGuard::spendCacheKey()))->toBeFalse();
});

test('dated model ids resolve their base catalog row and cost is computed per tier', function (): void {
    $lookup = resolve(ModelPriceLookup::class);
    $snapshot = $lookup->snapshotFor('openai', 'text-embedding-3-small-2026-01-01', false);

    expect($snapshot)->not->toBeNull()
        ->and($lookup->costOf(['prompt_tokens' => 500_000, 'completion_tokens' => 0, 'cache_read_input_tokens' => 0, 'cache_write_input_tokens' => 0, 'reasoning_tokens' => 0], $snapshot))
        ->toBe(0.01);
});
