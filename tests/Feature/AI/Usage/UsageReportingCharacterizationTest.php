<?php

declare(strict_types=1);

use App\Enums\FreePoolOverflowBehavior;
use App\Models\AiFreeUsagePool;
use App\Models\AiModelPrice;
use App\Models\AiToolInvocation;
use App\Models\AiUsageRecord;
use App\Models\User;
use App\Services\AiUsage\AiModelRateLimitExceededException;
use App\Services\AiUsage\AiRateLimitGuard;
use App\Services\AiUsage\AiUsageReporting;
use App\Settings\AiSettings;
use Carbon\CarbonImmutable;

test('the invocation drill-down keeps its full payload shape and numbers', function (): void {
    $user = User::factory()->create(['name' => 'Stella']);
    $parent = AiUsageRecord::factory()->create([
        'invocation_id' => 'inv-shape',
        'user_id' => $user->id,
        'prompt_tokens' => 1_000_000,
        'completion_tokens' => 500_000,
        'input_per_mtok' => 0.40,
        'output_per_mtok' => 1.60,
        'cache_read_per_mtok' => 0,
        'cache_write_per_mtok' => 0,
        'reasoning_per_mtok' => 0,
        'prompt_text' => 'What is on tonight?',
        'response_text' => 'Three new episodes.',
    ]);
    AiUsageRecord::factory()->create(['parent_invocation_id' => 'inv-shape', 'prompt_tokens' => 100, 'completion_tokens' => 20]);
    AiToolInvocation::factory()->create(['invocation_id' => 'inv-shape', 'duration_ms' => 42]);

    $detail = resolve(AiUsageReporting::class)->invocationDetail($parent->refresh());

    expect(array_keys($detail))->toBe(['record', 'user', 'tools', 'children', 'rates', 'breakdown', 'total_cost', 'scenario_breakdown', 'scenario_total_cost'])
        ->and(array_keys($detail['record']))->toBe([
            'id', 'invocation_id', 'agent_class', 'provider', 'model', 'prompt_tokens', 'completion_tokens',
            'cache_read_input_tokens', 'cache_write_input_tokens', 'reasoning_tokens', 'tool_calls_count',
            'prompt_text', 'response_text', 'price_source', 'conversation_id', 'status', 'kind', 'error_message',
            'parent_invocation_id', 'search_units', 'created_at',
        ])
        ->and($detail['record']['prompt_text'])->toBe('What is on tonight?')
        ->and($detail['record']['response_text'])->toBe('Three new episodes.')
        ->and($detail['user'])->toBe(['id' => $user->id, 'name' => 'Stella'])
        ->and(array_keys($detail['tools'][0]))->toBe(['id', 'tool_class', 'tool_invocation_id', 'status', 'error_code', 'duration_ms', 'created_at'])
        ->and(array_keys($detail['children'][0]))->toBe(['id', 'agent_class', 'model', 'status', 'total_tokens'])
        ->and($detail['children'][0]['total_tokens'])->toBe(120)
        ->and(array_keys($detail['rates']))->toBe(['source', 'input_per_mtok', 'output_per_mtok', 'cache_read_per_mtok', 'cache_write_per_mtok', 'reasoning_per_mtok', 'search_unit_per_k'])
        ->and($detail['rates']['source'])->toBe('snapshot')
        ->and(array_column($detail['breakdown'], 'label'))->toBe(['Input', 'Output', 'Cache read', 'Cache write', 'Reasoning'])
        ->and(round($detail['total_cost'], 6))->toBe(1.2)
        ->and($detail['scenario_breakdown'])->toBeNull()
        ->and($detail['scenario_total_cost'])->toBeNull();
});

test('totals net out a split free pool exactly as before', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 12:00:00', 'UTC'));
    $pool = AiFreeUsagePool::factory()->overflow(FreePoolOverflowBehavior::Split)->create(['free_input_tokens' => 500_000, 'free_output_tokens' => 100_000]);
    AiModelPrice::factory()->create([
        'provider' => 'gemini', 'model' => 'gemini-flash',
        'input_per_mtok' => 1.0, 'output_per_mtok' => 2.0, 'cache_read_per_mtok' => 0, 'cache_write_per_mtok' => 0, 'reasoning_per_mtok' => 0,
        'free_usage_pool_id' => $pool->id,
    ]);
    AiUsageRecord::factory()->create(['provider' => 'gemini', 'model' => 'gemini-flash-2026-06-01', 'prompt_tokens' => 1_000_000, 'completion_tokens' => 200_000]);

    $totals = resolve(AiUsageReporting::class)->totals(CarbonImmutable::now()->subDay());
    $pools = resolve(AiUsageReporting::class)->freePoolStatus();

    // Gross 1.00 + 0.40 = 1.40; forgiven 0.50 input + 0.20 output = 0.70.
    expect($totals['total_cost'])->toBe('0.700000')
        ->and($totals['total_invocations'])->toBe(1)
        ->and($pools[0]['used_input'])->toBe(1_000_000)
        ->and($pools[0]['used_output'])->toBe(200_000)
        ->and($pools[0]['models'][0]['model'])->toBe('gemini-flash');

    CarbonImmutable::setTestNow();
});

test('rate-limit status and the guard read the same rolling usage', function (): void {
    resolve(AiSettings::class)->setRateLimitsEnforced(true);
    $price = AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'gpt-5-mini']);
    $price->rateLimits()->create(['metric' => 'requests', 'period' => 'minute', 'limit_value' => 1]);
    AiUsageRecord::factory()->create(['provider' => 'openai', 'model' => 'gpt-5-mini-2026-01-01']);

    expect(resolve(AiUsageReporting::class)->rateLimitStatus()[0]['limits'][0]['used'])->toBe(1)
        ->and(AiUsageReporting::baseModel('gpt-5-mini-2026-01-01'))->toBe('gpt-5-mini');

    resolve(AiRateLimitGuard::class)->enforce('openai', 'gpt-5-mini-2026-01-01');
})->throws(AiModelRateLimitExceededException::class);
