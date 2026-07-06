<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Enums\FreeUsagePeriod;
use App\Models\AiFreeUsagePool;
use App\Models\AiModelPrice;
use App\Services\AiUsage\AiUsageReporting;
use App\Services\AiUsage\Scenario;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function seedPrice(string $provider, string $model, float $input, float $output, float $cacheRead = 0, float $cacheWrite = 0, float $reasoning = 0): void
{
    AiModelPrice::create([
        'provider' => $provider,
        'model' => $model,
        'input_per_mtok' => $input,
        'output_per_mtok' => $output,
        'cache_read_per_mtok' => $cacheRead,
        'cache_write_per_mtok' => $cacheWrite,
        'reasoning_per_mtok' => $reasoning,
    ]);
}

function seedUsage(array $attrs = []): int
{
    $now = now();

    return DB::table('ai_usage_records')->insertGetId(array_merge([
        'invocation_id' => 'inv-'.uniqid(),
        'agent_class' => MediaAgent::class,
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
        'cache_read_input_tokens' => 0,
        'cache_write_input_tokens' => 0,
        'reasoning_tokens' => 0,
        'tool_calls_count' => 0,
        'status' => 'success',
        'created_at' => $now,
        'updated_at' => $now,
    ], $attrs));
}

test('totals returns zeros when no usage records exist', function (): void {
    $totals = resolve(AiUsageReporting::class)->totals(CarbonImmutable::now()->subDay());

    expect($totals['total_invocations'])->toBe(0);
    expect($totals['total_tool_calls'])->toBe(0);
    expect($totals['total_tokens'])->toBe(0);
    expect((float) $totals['total_cost'])->toBe(0.0);
});

test('totals computes cost from prompt and completion tokens', function (): void {
    seedPrice('openai', 'gpt-5-mini', input: 0.40, output: 1.60);

    seedUsage(['prompt_tokens' => 1_000_000, 'completion_tokens' => 500_000]);

    $totals = resolve(AiUsageReporting::class)->totals(CarbonImmutable::now()->subDay());

    expect($totals['total_invocations'])->toBe(1);
    expect($totals['total_tokens'])->toBe(1_500_000);
    // 1M * $0.40 + 0.5M * $1.60 = 0.40 + 0.80 = 1.20
    expect((float) $totals['total_cost'])->toBe(1.20);
});

test('totals sums across multiple records', function (): void {
    seedPrice('openai', 'gpt-5-mini', input: 0.40, output: 1.60);

    seedUsage(['prompt_tokens' => 100_000, 'completion_tokens' => 100_000, 'tool_calls_count' => 2]);
    seedUsage(['prompt_tokens' => 200_000, 'completion_tokens' => 50_000, 'tool_calls_count' => 1]);

    $totals = resolve(AiUsageReporting::class)->totals(CarbonImmutable::now()->subDay());

    expect($totals['total_invocations'])->toBe(2);
    expect($totals['total_tool_calls'])->toBe(3);
    expect($totals['total_tokens'])->toBe(450_000);
});

test('records older than the window are excluded', function (): void {
    seedPrice('openai', 'gpt-5-mini', input: 0.40, output: 1.60);

    seedUsage(['prompt_tokens' => 1_000_000, 'created_at' => CarbonImmutable::now()->subDays(10)]);
    seedUsage(['prompt_tokens' => 500_000, 'created_at' => CarbonImmutable::now()->subHours(2)]);

    $totals = resolve(AiUsageReporting::class)->totals(CarbonImmutable::now()->subDay());

    expect($totals['total_invocations'])->toBe(1);
    expect($totals['total_tokens'])->toBe(500_000);
});

test('records with no matching price get zero cost contribution', function (): void {
    // Note: no price seeded for 'unknown-model'.
    seedUsage(['model' => 'unknown-model', 'prompt_tokens' => 1_000_000]);

    $totals = resolve(AiUsageReporting::class)->totals(CarbonImmutable::now()->subDay());

    expect($totals['total_invocations'])->toBe(1);
    expect((float) $totals['total_cost'])->toBe(0.0);
});

test('aggregateBy groups by the requested column and orders by cost desc', function (): void {
    seedPrice('openai', 'gpt-5-mini', input: 0.40, output: 1.60);
    seedPrice('anthropic', 'claude-haiku-4-5', input: 1.00, output: 5.00);

    seedUsage(['provider' => 'openai', 'model' => 'gpt-5-mini', 'prompt_tokens' => 100_000]);
    seedUsage(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'prompt_tokens' => 100_000]);
    seedUsage(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'prompt_tokens' => 100_000]);

    $rows = resolve(AiUsageReporting::class)->aggregateBy('provider', CarbonImmutable::now()->subDay());

    expect($rows)->toHaveCount(2);
    expect($rows->first()->key)->toBe('anthropic');
    expect((int) $rows->first()->invocations)->toBe(2);
});

test('totals with scenario uses flat rates instead of priced rates', function (): void {
    seedPrice('openai', 'gpt-5-mini', input: 0.40, output: 1.60);

    seedUsage(['prompt_tokens' => 1_000_000, 'completion_tokens' => 500_000]);

    $scenario = new Scenario(
        inputPerMtok: 1.00,
        outputPerMtok: 5.00,
        cacheReadPerMtok: 0,
        cacheWritePerMtok: 0,
        reasoningPerMtok: 0,
    );

    $totals = resolve(AiUsageReporting::class)->totals(
        CarbonImmutable::now()->subDay(),
        $scenario,
    );

    // 1M * $1 + 0.5M * $5 = $1 + $2.5 = $3.50
    expect((float) $totals['total_cost'])->toBe(3.50);
});

test('scenario applies even when no price row exists for the model', function (): void {
    // No price seeded for this model, but the scenario provides flat rates so
    // cost is still computed.
    seedUsage(['model' => 'mystery-model', 'prompt_tokens' => 1_000_000]);

    $scenario = new Scenario(2.00, 0, 0, 0, 0);

    $totals = resolve(AiUsageReporting::class)->totals(
        CarbonImmutable::now()->subDay(),
        $scenario,
    );

    expect((float) $totals['total_cost'])->toBe(2.00);
});

test('aggregateBy with scenario returns same groups but scenario-priced costs', function (): void {
    seedPrice('openai', 'gpt-5-mini', input: 0.40, output: 1.60);

    seedUsage(['provider' => 'openai', 'model' => 'gpt-5-mini', 'prompt_tokens' => 1_000_000]);
    seedUsage(['provider' => 'openai', 'model' => 'gpt-5-mini', 'prompt_tokens' => 500_000]);

    $scenario = new Scenario(2.00, 0, 0, 0, 0);

    $rows = resolve(AiUsageReporting::class)->aggregateBy(
        'provider',
        CarbonImmutable::now()->subDay(),
        $scenario,
    );

    expect($rows)->toHaveCount(1);
    // 1.5M tokens * $2 = $3
    expect((float) $rows->first()->total_cost)->toBe(3.00);
});

test('recentInvocations returns rows ordered by created_at desc with computed cost', function (): void {
    seedPrice('openai', 'gpt-5-mini', input: 0.40, output: 1.60);

    $olderId = seedUsage(['prompt_tokens' => 100_000, 'created_at' => CarbonImmutable::now()->subHours(3)]);
    $newerId = seedUsage(['prompt_tokens' => 200_000, 'created_at' => CarbonImmutable::now()->subMinutes(5)]);

    $rows = resolve(AiUsageReporting::class)->recentInvocations(CarbonImmutable::now()->subDay());

    expect($rows)->toHaveCount(2);
    expect($rows->first()->id)->toBe($newerId);
    expect($rows->last()->id)->toBe($olderId);
    expect((float) $rows->first()->cost)->toBeGreaterThan(0);
});

test('totals matches dated model id against base model price', function (): void {
    seedPrice('openai', 'gpt-5-mini', input: 0.40, output: 1.60);

    seedUsage(['model' => 'gpt-5-mini-2025-09-23', 'prompt_tokens' => 1_000_000]);

    $totals = resolve(AiUsageReporting::class)->totals(CarbonImmutable::now()->subDay());

    // 1M tokens * $0.40 = $0.40 — base-name price applies despite the
    // dated suffix on the recorded model id.
    expect((float) $totals['total_cost'])->toBe(0.40);
});

test('totals still uses exact match when no dated suffix is present', function (): void {
    seedPrice('openai', 'gpt-5-mini', input: 0.40, output: 1.60);

    seedUsage(['model' => 'gpt-5-mini', 'prompt_tokens' => 1_000_000]);

    $totals = resolve(AiUsageReporting::class)->totals(CarbonImmutable::now()->subDay());

    expect((float) $totals['total_cost'])->toBe(0.40);
});

test('totals prefer the row snapshot over the live catalog price', function (): void {
    // Live catalog says input is $0.40, but the row was recorded when input
    // was $1.00 — the snapshot should win so historical cost stays anchored
    // to whatever the price was at call time.
    seedPrice('openai', 'gpt-5-mini', input: 0.40, output: 1.60);

    seedUsage([
        'prompt_tokens' => 1_000_000,
        'input_per_mtok' => 1.00,
        'output_per_mtok' => 0,
        'cache_read_per_mtok' => 0,
        'cache_write_per_mtok' => 0,
        'reasoning_per_mtok' => 0,
        'price_source' => 'live',
    ]);

    $totals = resolve(AiUsageReporting::class)->totals(CarbonImmutable::now()->subDay());

    expect((float) $totals['total_cost'])->toBe(1.00);
});

test('totals fall back to live catalog when snapshot is null', function (): void {
    seedPrice('openai', 'gpt-5-mini', input: 0.40, output: 1.60);

    // No snapshot fields set — listener missed it / row pre-dates snapshots.
    seedUsage(['prompt_tokens' => 1_000_000]);

    $totals = resolve(AiUsageReporting::class)->totals(CarbonImmutable::now()->subDay());

    expect((float) $totals['total_cost'])->toBe(0.40);
});

function seedPooledPrice(AiFreeUsagePool $aiFreeUsagePool, string $provider, string $model, float $input, float $output): AiModelPrice
{
    return AiModelPrice::create([
        'provider' => $provider,
        'model' => $model,
        'input_per_mtok' => $input,
        'output_per_mtok' => $output,
        'cache_read_per_mtok' => 0,
        'cache_write_per_mtok' => 0,
        'reasoning_per_mtok' => 0,
        'free_usage_pool_id' => $aiFreeUsagePool->id,
    ]);
}

test('totals subtracts pooled free usage from gross cost', function (): void {
    $pool = AiFreeUsagePool::factory()->create([
        'free_input_tokens' => 500_000,
        'free_output_tokens' => 200_000,
    ]);
    seedPooledPrice($pool, 'openai', 'gpt-5-mini', input: 0.40, output: 1.60);

    seedUsage(['prompt_tokens' => 1_000_000, 'completion_tokens' => 500_000]);

    $totals = resolve(AiUsageReporting::class)->totals(CarbonImmutable::now()->subDay());

    // Gross: 1M * 0.40 + 0.5M * 1.60 = 1.20
    // Free:  0.5M * 0.40 + 0.2M * 1.60 = 0.52
    expect((float) $totals['total_cost'])->toBe(0.68);
});

test('totals never go negative when usage stays under the pool quota', function (): void {
    $pool = AiFreeUsagePool::factory()->create([
        'free_input_tokens' => 5_000_000,
        'free_output_tokens' => 5_000_000,
    ]);
    seedPooledPrice($pool, 'openai', 'gpt-5-mini', input: 0.40, output: 1.60);

    seedUsage(['prompt_tokens' => 1_000_000, 'completion_tokens' => 500_000]);

    $totals = resolve(AiUsageReporting::class)->totals(CarbonImmutable::now()->subDay());

    expect((float) $totals['total_cost'])->toBe(0.0);
});

test('pool free tokens are shared across member models', function (): void {
    $pool = AiFreeUsagePool::factory()->create([
        'free_input_tokens' => 1_000_000,
        'free_output_tokens' => null,
    ]);
    seedPooledPrice($pool, 'google', 'gemini-flash', input: 1.00, output: 0);
    seedPooledPrice($pool, 'google', 'gemini-pro', input: 3.00, output: 0);

    // 1.5M + 0.5M input = 2M pool usage against a 1M shared cap. Forgiven
    // input is allocated proportionally: flash 750k * $1, pro 250k * $3.
    seedUsage(['provider' => 'google', 'model' => 'gemini-flash', 'prompt_tokens' => 1_500_000]);
    seedUsage(['provider' => 'google', 'model' => 'gemini-pro', 'prompt_tokens' => 500_000]);

    $totals = resolve(AiUsageReporting::class)->totals(CarbonImmutable::now()->subDay());

    // Gross: 1.5M * $1 + 0.5M * $3 = 3.00. Discount: 0.75 + 0.75 = 1.50.
    expect((float) $totals['total_cost'])->toBe(1.50);
});

test('unified pools draw input and output from one shared budget', function (): void {
    $pool = AiFreeUsagePool::factory()->unified(600_000)->create();
    seedPooledPrice($pool, 'openai', 'gpt-5-mini', input: 1.00, output: 2.00);

    seedUsage(['prompt_tokens' => 400_000, 'completion_tokens' => 400_000]);

    $totals = resolve(AiUsageReporting::class)->totals(CarbonImmutable::now()->subDay());

    // Gross: 0.4M * $1 + 0.4M * $2 = 1.20. Total used 800k vs 600k cap →
    // ratio 0.75 forgiven: discount = 1.20 * 0.75 = 0.90.
    expect((float) $totals['total_cost'])->toBe(0.30);
});

test('daily pools cap forgiveness per UTC day bucket', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-08 12:00:00', 'UTC'));

    $pool = AiFreeUsagePool::factory()
        ->period(FreeUsagePeriod::Daily)
        ->create(['free_input_tokens' => 100_000, 'free_output_tokens' => null]);
    seedPooledPrice($pool, 'openai', 'gpt-5-mini', input: 1.00, output: 0);

    // Two separate UTC days, each over the daily 100k cap.
    seedUsage(['prompt_tokens' => 150_000, 'created_at' => CarbonImmutable::parse('2026-07-07 10:00:00', 'UTC')]);
    seedUsage(['prompt_tokens' => 150_000, 'created_at' => CarbonImmutable::parse('2026-07-08 10:00:00', 'UTC')]);

    $totals = resolve(AiUsageReporting::class)->totals(CarbonImmutable::now()->subDays(7));

    // Gross 0.30; forgiven 100k per day = 0.20 discount.
    expect((float) $totals['total_cost'])->toBe(0.10);

    CarbonImmutable::setTestNow();
});

test('freePoolStatus reports pool usage for the current period only', function (): void {
    // Wednesday 2026-07-08; weekly period started Monday 2026-07-06 UTC.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-08 12:00:00', 'UTC'));

    $pool = AiFreeUsagePool::factory()
        ->period(FreeUsagePeriod::Weekly)
        ->create(['name' => 'Weekly pool', 'free_input_tokens' => 1_000_000, 'free_output_tokens' => 500_000, 'documentation_url' => 'https://example.com/free']);
    seedPooledPrice($pool, 'openai', 'gpt-5-mini', input: 0.40, output: 1.60);

    seedUsage(['prompt_tokens' => 200_000, 'completion_tokens' => 50_000, 'created_at' => CarbonImmutable::parse('2026-07-06 08:00:00', 'UTC')]);
    // Sunday before the period boundary — must be excluded.
    seedUsage(['prompt_tokens' => 900_000, 'created_at' => CarbonImmutable::parse('2026-07-05 08:00:00', 'UTC')]);

    $rows = resolve(AiUsageReporting::class)->freePoolStatus();

    expect($rows)->toHaveCount(1);
    expect($rows[0]['name'])->toBe('Weekly pool');
    expect($rows[0]['period'])->toBe('weekly');
    expect($rows[0]['documentation_url'])->toBe('https://example.com/free');
    expect($rows[0]['used_input'])->toBe(200_000);
    expect($rows[0]['used_output'])->toBe(50_000);
    expect($rows[0]['used_total'])->toBe(250_000);
    expect($rows[0]['free_input'])->toBe(1_000_000);
    expect($rows[0]['models'])->toHaveCount(1);
    expect($rows[0]['models'][0]['model'])->toBe('gpt-5-mini');

    CarbonImmutable::setTestNow();
});

test('freePoolStatus matches dated model ids against pooled base models', function (): void {
    $pool = AiFreeUsagePool::factory()->create(['free_input_tokens' => 1_000_000]);
    seedPooledPrice($pool, 'openai', 'gpt-5-mini', input: 0.40, output: 1.60);

    seedUsage(['model' => 'gpt-5-mini-2025-09-23', 'prompt_tokens' => 300_000]);

    $rows = resolve(AiUsageReporting::class)->freePoolStatus();

    expect($rows)->toHaveCount(1);
    expect($rows[0]['used_input'])->toBe(300_000);
});

test('freePoolStatus lists configured pools even with zero usage', function (): void {
    AiFreeUsagePool::factory()->create(['name' => 'Idle pool', 'free_input_tokens' => 1_000_000]);

    $rows = resolve(AiUsageReporting::class)->freePoolStatus();

    expect($rows)->toHaveCount(1);
    expect($rows[0]['used_total'])->toBe(0);
});

test('totals with scenario accepts fractional rates', function (): void {
    seedUsage([
        'prompt_tokens' => 1_000_000,
        'completion_tokens' => 500_000,
    ]);

    $scenario = new Scenario(0.75, 4.50, 0.25, 0.25, 0);

    $totals = resolve(AiUsageReporting::class)->totals(
        CarbonImmutable::now()->subDay(),
        $scenario,
    );

    // 1M * $0.75 + 0.5M * $4.50 = 0.75 + 2.25 = 3.00
    expect((float) $totals['total_cost'])->toBe(3.00);
});
