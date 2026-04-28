<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
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
