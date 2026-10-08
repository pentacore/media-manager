<?php

declare(strict_types=1);

use App\Enums\FreePoolOverflowBehavior;
use App\Models\AiFreeUsagePool;
use App\Models\AiModelPrice;
use App\Models\AiUsageRecord;
use App\Services\AiUsage\PoolHeadroom;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
});

function headroomPool(array $attributes = []): AiFreeUsagePool
{
    return AiFreeUsagePool::factory()->overflow(FreePoolOverflowBehavior::Split)->create(['name' => 'Gemini free', ...$attributes]);
}

function headroomPrice(string $model, ?AiFreeUsagePool $aiFreeUsagePool): void
{
    AiModelPrice::factory()->create(['provider' => 'gemini', 'model' => $model, 'free_usage_pool_id' => $aiFreeUsagePool?->id]);
}

function headroomUsage(string $model, int $input, int $output = 0): void
{
    AiUsageRecord::factory()->create(['provider' => 'gemini', 'model' => $model, 'prompt_tokens' => $input, 'completion_tokens' => $output]);
}

function headroom(): PoolHeadroom
{
    $poolHeadroom = resolve(PoolHeadroom::class);
    $poolHeadroom->flush();

    return $poolHeadroom;
}

test('a unified pool reports the share and tokens left of its total cap', function (): void {
    $pool = headroomPool(['unified' => true, 'free_input_tokens' => null, 'free_output_tokens' => null, 'free_total_tokens' => 1_000_000]);
    headroomPrice('gemini-2.5-flash', $pool);
    headroomUsage('gemini-2.5-flash', 600_000, 150_000);

    $figures = headroom()->forModel('gemini', 'gemini-2.5-flash');

    expect($figures?->toArray())->toBe(['name' => 'Gemini free', 'percent_left' => 25.0, 'tokens_left' => 250_000]);
});

test('a split pool reports its tightest capped dimension', function (): void {
    $pool = headroomPool(['free_input_tokens' => 1_000_000, 'free_output_tokens' => 100_000]);
    headroomPrice('gemini-2.5-flash', $pool);
    headroomUsage('gemini-2.5-flash', 100_000, 80_000);

    $figures = headroom()->forModel('gemini', 'gemini-2.5-flash');

    expect($figures?->percentLeft)->toBe(20.0)
        ->and($figures?->tokensLeft)->toBe(20_000);
});

test('an uncapped dimension is ignored and a pool with no caps is always eligible', function (): void {
    $outputOnly = headroomPool(['free_input_tokens' => null, 'free_output_tokens' => 100_000]);
    $uncapped = headroomPool(['name' => 'Open', 'free_input_tokens' => null, 'free_output_tokens' => null]);
    headroomPrice('gemini-2.5-flash', $outputOnly);
    headroomPrice('gemini-2.5-pro', $uncapped);
    headroomUsage('gemini-2.5-flash', 5_000_000, 50_000);

    expect(headroom()->forModel('gemini', 'gemini-2.5-flash')?->percentLeft)->toBe(50.0)
        ->and(headroom()->forModel('gemini', 'gemini-2.5-pro'))->toBeNull();
});

test('usage above the cap and a zero cap floor at nothing left', function (): void {
    $over = headroomPool(['unified' => true, 'free_input_tokens' => null, 'free_output_tokens' => null, 'free_total_tokens' => 100]);
    $zero = headroomPool(['name' => 'Zero', 'unified' => true, 'free_input_tokens' => null, 'free_output_tokens' => null, 'free_total_tokens' => 0]);
    headroomPrice('gemini-2.5-flash', $over);
    headroomPrice('gemini-2.5-pro', $zero);
    headroomUsage('gemini-2.5-flash', 500);

    expect(headroom()->forModel('gemini', 'gemini-2.5-flash')?->toArray())->toBe(['name' => 'Gemini free', 'percent_left' => 0.0, 'tokens_left' => 0])
        ->and(headroom()->forModel('gemini', 'gemini-2.5-pro')?->toArray())->toBe(['name' => 'Zero', 'percent_left' => 0.0, 'tokens_left' => 0]);
});

test('a model without a price row or pool has no headroom figures', function (): void {
    headroomPrice('gemini-2.5-flash', null);

    expect(headroom()->forModel('gemini', 'gemini-2.5-flash'))->toBeNull()
        ->and(headroom()->forModel('gemini', 'unknown-model'))->toBeNull();
});

test('a dated model id matches its base price row', function (): void {
    $pool = headroomPool(['unified' => true, 'free_input_tokens' => null, 'free_output_tokens' => null, 'free_total_tokens' => 1_000]);
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5-mini', 'free_usage_pool_id' => $pool->id]);

    expect(headroom()->forModel('openai', 'gpt-5-mini-2025-09-23')?->name)->toBe('Gemini free');
});

test('figures are cached until flushed and saving a pool flushes them', function (): void {
    $pool = headroomPool(['unified' => true, 'free_input_tokens' => null, 'free_output_tokens' => null, 'free_total_tokens' => 1_000]);
    headroomPrice('gemini-2.5-flash', $pool);

    expect(headroom()->forModel('gemini', 'gemini-2.5-flash')?->tokensLeft)->toBe(1_000);

    headroomUsage('gemini-2.5-flash', 400);
    resolve(PoolHeadroom::class)->flush();
    Cache::put(PoolHeadroom::cacheKey(), [$pool->id => ['name' => 'Gemini free', 'percent_left' => 100.0, 'tokens_left' => 1_000]], 60);

    expect(resolve(PoolHeadroom::class)->forModel('gemini', 'gemini-2.5-flash')?->tokensLeft)->toBe(1_000);

    $pool->update(['free_total_tokens' => 2_000]);

    expect(resolve(PoolHeadroom::class)->forModel('gemini', 'gemini-2.5-flash')?->tokensLeft)->toBe(1_600);
});

test('the cache key changes with the UTC date', function (): void {
    $this->travelTo(now('UTC')->setTime(23, 59));
    $today = PoolHeadroom::cacheKey();
    $this->travel(2)->minutes();

    expect(PoolHeadroom::cacheKey())->not->toBe($today);
});
