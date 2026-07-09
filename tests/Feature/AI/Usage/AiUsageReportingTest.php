<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Enums\FreePoolOverflowBehavior;
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

function seedPooledPrice(AiFreeUsagePool $aiFreeUsagePool, string $provider, string $model, float $input, float $output, float $cacheRead = 0, float $cacheWrite = 0): AiModelPrice
{
    return AiModelPrice::create([
        'provider' => $provider,
        'model' => $model,
        'input_per_mtok' => $input,
        'output_per_mtok' => $output,
        'cache_read_per_mtok' => $cacheRead,
        'cache_write_per_mtok' => $cacheWrite,
        'reasoning_per_mtok' => 0,
        'free_usage_pool_id' => $aiFreeUsagePool->id,
    ]);
}

test('totals subtracts pooled free usage from gross cost', function (): void {
    $pool = AiFreeUsagePool::factory()->overflow(FreePoolOverflowBehavior::Split)->create([
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
    $pool = AiFreeUsagePool::factory()->overflow(FreePoolOverflowBehavior::Split)->create([
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
    $pool = AiFreeUsagePool::factory()->unified(600_000)->overflow(FreePoolOverflowBehavior::Split)->create();
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
        ->overflow(FreePoolOverflowBehavior::Split)
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

test('pool cap already spent earlier in the period is not re-granted to a narrower window', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-08 12:00:00', 'UTC'));

    $pool = AiFreeUsagePool::factory()
        ->period(FreeUsagePeriod::Monthly)
        ->overflow(FreePoolOverflowBehavior::Split)
        ->create(['free_input_tokens' => 500_000, 'free_output_tokens' => null]);
    seedPooledPrice($pool, 'openai', 'gpt-5-mini', input: 1.00, output: 0);

    // 900k earlier in the month + 100k inside the "today" window: the month
    // bucket totals 1M against a 500k cap, so only half of any usage in it
    // is free — including today's slice, even though today alone is under
    // the cap.
    seedUsage(['prompt_tokens' => 900_000, 'created_at' => CarbonImmutable::parse('2026-07-02 10:00:00', 'UTC')]);
    seedUsage(['prompt_tokens' => 100_000, 'created_at' => CarbonImmutable::parse('2026-07-08 10:00:00', 'UTC')]);

    $totals = resolve(AiUsageReporting::class)->totals(CarbonImmutable::now('UTC')->startOfDay());

    // Window gross: 100k * $1 = 0.10. Month-bucket forgiven ratio =
    // min(1M, 500k) / 1M = 0.5 → discount 0.05, not the full 0.10.
    expect((float) $totals['total_cost'])->toBe(0.05);

    CarbonImmutable::setTestNow();
});

test('totals with a null window includes all records', function (): void {
    seedPrice('openai', 'gpt-5-mini', input: 1.00, output: 0);

    seedUsage(['prompt_tokens' => 1_000_000, 'created_at' => CarbonImmutable::now()->subDays(400)]);
    seedUsage(['prompt_tokens' => 500_000]);

    $totals = resolve(AiUsageReporting::class)->totals(null);

    expect($totals['total_invocations'])->toBe(2);
    expect((float) $totals['total_cost'])->toBe(1.50);
});

test('pool discount with a null window forgives per period across all history', function (): void {
    $pool = AiFreeUsagePool::factory()
        ->period(FreeUsagePeriod::Monthly)
        ->overflow(FreePoolOverflowBehavior::Split)
        ->create(['free_input_tokens' => 100_000, 'free_output_tokens' => null]);
    seedPooledPrice($pool, 'openai', 'gpt-5-mini', input: 1.00, output: 0);

    // Two separate months, each over the monthly 100k cap.
    seedUsage(['prompt_tokens' => 150_000, 'created_at' => CarbonImmutable::parse('2026-05-10 10:00:00', 'UTC')]);
    seedUsage(['prompt_tokens' => 150_000, 'created_at' => CarbonImmutable::parse('2026-06-10 10:00:00', 'UTC')]);

    $totals = resolve(AiUsageReporting::class)->totals(null);

    // Gross 0.30; 100k forgiven per month bucket → discount 0.20.
    expect((float) $totals['total_cost'])->toBe(0.10);
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

test('fit-or-paid pools bill the whole request when it does not fit the remaining quota', function (): void {
    $pool = AiFreeUsagePool::factory()->create([
        'free_input_tokens' => 500_000,
        'free_output_tokens' => null,
    ]);
    seedPooledPrice($pool, 'openai', 'gpt-5-mini', input: 1.00, output: 0);

    // 600k > 500k quota: OpenAI-style accounting bills the entire request
    // instead of forgiving the first 500k.
    seedUsage(['prompt_tokens' => 600_000]);

    $totals = resolve(AiUsageReporting::class)->totals(CarbonImmutable::now()->subDay());

    expect((float) $totals['total_cost'])->toBe(0.60);
});

test('fit-or-paid pools keep the quota for a later request that fits', function (): void {
    $pool = AiFreeUsagePool::factory()->create([
        'free_input_tokens' => 500_000,
        'free_output_tokens' => null,
    ]);
    seedPooledPrice($pool, 'openai', 'gpt-5-mini', input: 1.00, output: 0);

    // The oversized request is billed and leaves the pool untouched, so the
    // later 400k request still fits and is free.
    seedUsage(['prompt_tokens' => 600_000, 'created_at' => CarbonImmutable::now()->subHours(2)]);
    seedUsage(['prompt_tokens' => 400_000, 'created_at' => CarbonImmutable::now()->subHour()]);

    $totals = resolve(AiUsageReporting::class)->totals(CarbonImmutable::now()->subDay());

    expect((float) $totals['total_cost'])->toBe(0.60);
});

test('fit-or-paid pools consume quota chronologically', function (): void {
    $pool = AiFreeUsagePool::factory()->create([
        'free_input_tokens' => 500_000,
        'free_output_tokens' => null,
    ]);
    seedPooledPrice($pool, 'openai', 'gpt-5-mini', input: 1.00, output: 0);

    // First request fits (500k → 200k remaining); the second no longer does.
    seedUsage(['prompt_tokens' => 300_000, 'created_at' => CarbonImmutable::now()->subHours(2)]);
    seedUsage(['prompt_tokens' => 300_000, 'created_at' => CarbonImmutable::now()->subHour()]);

    $totals = resolve(AiUsageReporting::class)->totals(CarbonImmutable::now()->subDay());

    expect((float) $totals['total_cost'])->toBe(0.30);
});

test('fit-or-paid unified pools require input plus output to fit together', function (): void {
    $pool = AiFreeUsagePool::factory()->unified(600_000)->create();
    seedPooledPrice($pool, 'openai', 'gpt-5-mini', input: 1.00, output: 2.00);

    // 400k + 300k = 700k > 600k: whole request paid. The later 300k request
    // fits the untouched budget and is fully forgiven.
    seedUsage(['prompt_tokens' => 400_000, 'completion_tokens' => 300_000, 'created_at' => CarbonImmutable::now()->subHours(2)]);
    seedUsage(['prompt_tokens' => 200_000, 'completion_tokens' => 100_000, 'created_at' => CarbonImmutable::now()->subHour()]);

    $totals = resolve(AiUsageReporting::class)->totals(CarbonImmutable::now()->subDay());

    // Paid: 0.4M * $1 + 0.3M * $2 = 1.00
    expect((float) $totals['total_cost'])->toBe(1.00);
});

test('fit-or-paid split pools require every capped dimension to fit', function (): void {
    $pool = AiFreeUsagePool::factory()->create([
        'free_input_tokens' => 500_000,
        'free_output_tokens' => 100_000,
    ]);
    seedPooledPrice($pool, 'openai', 'gpt-5-mini', input: 1.00, output: 2.00);

    // Input fits but output (200k > 100k) does not — the entire request is
    // billed, both sides included.
    seedUsage(['prompt_tokens' => 100_000, 'completion_tokens' => 200_000]);

    $totals = resolve(AiUsageReporting::class)->totals(CarbonImmutable::now()->subDay());

    // 0.1M * $1 + 0.2M * $2 = 0.50
    expect((float) $totals['total_cost'])->toBe(0.50);
});

test('fit-or-paid pools leave uncapped dimensions billed even on fitting requests', function (): void {
    $pool = AiFreeUsagePool::factory()->create([
        'free_input_tokens' => 500_000,
        'free_output_tokens' => null,
    ]);
    seedPooledPrice($pool, 'openai', 'gpt-5-mini', input: 1.00, output: 2.00);

    // Input fits and is forgiven; output has no free budget and stays paid.
    seedUsage(['prompt_tokens' => 400_000, 'completion_tokens' => 1_000_000]);

    $totals = resolve(AiUsageReporting::class)->totals(CarbonImmutable::now()->subDay());

    // Paid output: 1M * $2 = 2.00; input forgiven.
    expect((float) $totals['total_cost'])->toBe(2.00);
});

test('fit-or-paid quota spent earlier in the period is not re-granted to a narrower window', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-08 12:00:00', 'UTC'));

    $pool = AiFreeUsagePool::factory()
        ->period(FreeUsagePeriod::Monthly)
        ->create(['free_input_tokens' => 500_000, 'free_output_tokens' => null]);
    seedPooledPrice($pool, 'openai', 'gpt-5-mini', input: 1.00, output: 0);

    // 300k earlier in the month fits and shrinks the quota to 200k, so
    // today's 300k request no longer fits and is billed in full.
    seedUsage(['prompt_tokens' => 300_000, 'created_at' => CarbonImmutable::parse('2026-07-02 10:00:00', 'UTC')]);
    seedUsage(['prompt_tokens' => 300_000, 'created_at' => CarbonImmutable::parse('2026-07-08 10:00:00', 'UTC')]);

    $totals = resolve(AiUsageReporting::class)->totals(CarbonImmutable::now('UTC')->startOfDay());

    expect((float) $totals['total_cost'])->toBe(0.30);

    CarbonImmutable::setTestNow();
});

test('fit-or-paid pools reset the quota each period bucket', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-08 12:00:00', 'UTC'));

    $pool = AiFreeUsagePool::factory()
        ->period(FreeUsagePeriod::Daily)
        ->create(['free_input_tokens' => 100_000, 'free_output_tokens' => null]);
    seedPooledPrice($pool, 'openai', 'gpt-5-mini', input: 1.00, output: 0);

    // Each day gets its own quota: both 100k requests fit their own day,
    // the 150k request never fits and is billed.
    seedUsage(['prompt_tokens' => 100_000, 'created_at' => CarbonImmutable::parse('2026-07-07 10:00:00', 'UTC')]);
    seedUsage(['prompt_tokens' => 150_000, 'created_at' => CarbonImmutable::parse('2026-07-07 11:00:00', 'UTC')]);
    seedUsage(['prompt_tokens' => 100_000, 'created_at' => CarbonImmutable::parse('2026-07-08 10:00:00', 'UTC')]);

    $totals = resolve(AiUsageReporting::class)->totals(CarbonImmutable::now()->subDays(7));

    expect((float) $totals['total_cost'])->toBe(0.15);

    CarbonImmutable::setTestNow();
});

test('freePoolStatus counts only fitting requests for fit-or-paid pools', function (): void {
    $pool = AiFreeUsagePool::factory()->create([
        'name' => 'OpenAI daily free',
        'free_input_tokens' => 500_000,
        'free_output_tokens' => null,
    ]);
    seedPooledPrice($pool, 'openai', 'gpt-5-mini', input: 1.00, output: 0);

    // The 600k request never draws from the pool; only the 200k one does.
    seedUsage(['prompt_tokens' => 600_000, 'created_at' => CarbonImmutable::now()->subHours(2)]);
    seedUsage(['prompt_tokens' => 200_000, 'created_at' => CarbonImmutable::now()->subHour()]);

    $rows = resolve(AiUsageReporting::class)->freePoolStatus();

    expect($rows)->toHaveCount(1);
    expect($rows[0]['used_input'])->toBe(200_000);
    expect($rows[0]['used_total'])->toBe(200_000);
    expect($rows[0]['models'])->toHaveCount(1);
    expect($rows[0]['models'][0]['used_input'])->toBe(200_000);
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

test('split pools count cached read and write tokens against the input quota', function (): void {
    $pool = AiFreeUsagePool::factory()->overflow(FreePoolOverflowBehavior::Split)->create([
        'free_input_tokens' => 500_000,
        'free_output_tokens' => null,
    ]);
    seedPooledPrice($pool, 'openai', 'gpt-5-mini', input: 1.00, output: 2.00, cacheRead: 0.10, cacheWrite: 1.25);

    seedUsage([
        'prompt_tokens' => 400_000,
        'cache_read_input_tokens' => 400_000,
        'cache_write_input_tokens' => 200_000,
    ]);

    $totals = resolve(AiUsageReporting::class)->totals(CarbonImmutable::now()->subDay());

    // Input-side consumption: 400k + 400k + 200k = 1M vs 500k cap → ratio 0.5.
    // Gross: 0.4M*$1 + 0.4M*$0.10 + 0.2M*$1.25 = 0.69.
    // Forgiven: 0.69 * 0.5 = 0.345 → total 0.345.
    expect((float) $totals['total_cost'])->toBe(0.345);
});

test('unified pools count cached tokens against the shared budget', function (): void {
    $pool = AiFreeUsagePool::factory()->unified(600_000)->overflow(FreePoolOverflowBehavior::Split)->create();
    seedPooledPrice($pool, 'openai', 'gpt-5-mini', input: 1.00, output: 2.00, cacheRead: 0.10);

    seedUsage([
        'prompt_tokens' => 400_000,
        'cache_read_input_tokens' => 400_000,
    ]);

    $totals = resolve(AiUsageReporting::class)->totals(CarbonImmutable::now()->subDay());

    // Total consumption: 800k vs 600k cap → ratio 0.75.
    // Gross: 0.4M*$1 + 0.4M*$0.10 = 0.44. Forgiven 0.33 → total 0.11.
    expect((float) $totals['total_cost'])->toBe(0.11);
});

test('freePoolStatus counts cached read and write tokens as used input', function (): void {
    $pool = AiFreeUsagePool::factory()->overflow(FreePoolOverflowBehavior::Split)->create([
        'free_input_tokens' => 1_000_000,
        'free_output_tokens' => null,
    ]);
    seedPooledPrice($pool, 'openai', 'gpt-5-mini', input: 1.00, output: 2.00);

    seedUsage([
        'prompt_tokens' => 100_000,
        'cache_read_input_tokens' => 50_000,
        'cache_write_input_tokens' => 25_000,
    ]);

    $rows = resolve(AiUsageReporting::class)->freePoolStatus();

    expect($rows)->toHaveCount(1);
    expect($rows[0]['used_input'])->toBe(175_000);
    expect($rows[0]['models'][0]['used_input'])->toBe(175_000);
});

test('fit-or-paid pools include cached tokens when checking whether a request fits', function (): void {
    $pool = AiFreeUsagePool::factory()->overflow(FreePoolOverflowBehavior::FitOrPaid)->create([
        'free_input_tokens' => 500_000,
        'free_output_tokens' => null,
    ]);
    seedPooledPrice($pool, 'openai', 'gpt-5-mini', input: 1.00, output: 2.00, cacheRead: 0.10);

    seedUsage([
        'prompt_tokens' => 300_000,
        'cache_read_input_tokens' => 300_000,
    ]);

    $totals = resolve(AiUsageReporting::class)->totals(CarbonImmutable::now()->subDay());

    // 300k prompt + 300k cached = 600k > 500k cap → does not fit, billed in full.
    // Gross: 0.3M*$1 + 0.3M*$0.10 = 0.33.
    expect((float) $totals['total_cost'])->toBe(0.33);
});

test('fit-or-paid pools forgive cached tokens at their own rates on fitting requests', function (): void {
    $pool = AiFreeUsagePool::factory()->overflow(FreePoolOverflowBehavior::FitOrPaid)->create([
        'free_input_tokens' => 1_000_000,
        'free_output_tokens' => null,
    ]);
    seedPooledPrice($pool, 'openai', 'gpt-5-mini', input: 1.00, output: 2.00, cacheRead: 0.10, cacheWrite: 1.25);

    seedUsage([
        'prompt_tokens' => 300_000,
        'cache_read_input_tokens' => 300_000,
        'cache_write_input_tokens' => 100_000,
        'completion_tokens' => 100_000,
    ]);

    $totals = resolve(AiUsageReporting::class)->totals(CarbonImmutable::now()->subDay());

    // 700k input-side fits the 1M cap → input classes forgiven at their own
    // rates (0.3 + 0.03 + 0.125 = 0.455). Output is uncapped → stays billed:
    // 0.1M*$2 = 0.20.
    expect((float) $totals['total_cost'])->toBe(0.20);
});

test('freePoolStatus counts cached tokens for fit-or-paid pools', function (): void {
    $pool = AiFreeUsagePool::factory()->overflow(FreePoolOverflowBehavior::FitOrPaid)->create([
        'free_input_tokens' => 1_000_000,
        'free_output_tokens' => null,
    ]);
    seedPooledPrice($pool, 'openai', 'gpt-5-mini', input: 1.00, output: 2.00);

    seedUsage([
        'prompt_tokens' => 100_000,
        'cache_read_input_tokens' => 50_000,
    ]);

    $rows = resolve(AiUsageReporting::class)->freePoolStatus();

    expect($rows)->toHaveCount(1);
    expect($rows[0]['used_input'])->toBe(150_000);
    expect($rows[0]['models'][0]['used_input'])->toBe(150_000);
});
