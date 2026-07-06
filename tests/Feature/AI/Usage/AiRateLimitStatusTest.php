<?php

declare(strict_types=1);

use App\Models\AiModelPrice;
use App\Services\AiUsage\AiUsageReporting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function seedLimitedPrice(array $attributes = [], array $limits = []): AiModelPrice
{
    $price = AiModelPrice::factory()->create($attributes);
    $price->rateLimits()->createMany($limits);

    return $price;
}

function seedLimitedUsage(string $provider, string $model, int $prompt, int $completion, CarbonImmutable $at): void
{
    // DB insert, not Eloquent create: created_at is not fillable and an
    // Eloquent create would silently stamp "now", breaking window tests.
    // Same approach as AiUsageReportingTest::seedUsage().
    DB::table('ai_usage_records')->insert([
        'invocation_id' => 'inv-'.uniqid(),
        'agent_class' => 'TestAgent',
        'provider' => $provider,
        'model' => $model,
        'prompt_tokens' => $prompt,
        'completion_tokens' => $completion,
        'cache_read_input_tokens' => 0,
        'cache_write_input_tokens' => 0,
        'reasoning_tokens' => 0,
        'tool_calls_count' => 0,
        'status' => 'success',
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

test('rateLimitStatus counts requests and tokens inside each rolling window only', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 13:45:30', 'UTC'));

    seedLimitedPrice(['provider' => 'openai', 'model' => 'gpt-5-mini'], [
        ['metric' => 'requests', 'period' => 'minute', 'limit_value' => 500],
        ['metric' => 'tokens', 'period' => 'day', 'limit_value' => 1_000_000],
    ]);

    $now = CarbonImmutable::now('UTC');
    seedLimitedUsage('openai', 'gpt-5-mini', 1_000, 500, $now->subSeconds(30));   // inside minute + day
    seedLimitedUsage('openai', 'gpt-5-mini', 2_000, 1_000, $now->subMinutes(5)); // outside minute, inside day
    seedLimitedUsage('openai', 'gpt-5-mini', 4_000, 2_000, $now->subDays(2));    // outside both

    $rows = app(AiUsageReporting::class)->rateLimitStatus();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['provider'])->toBe('openai')
        ->and($rows[0]['model'])->toBe('gpt-5-mini')
        ->and($rows[0]['limits'][0])->toBe(['metric' => 'requests', 'period' => 'minute', 'limit_value' => 500, 'used' => 1])
        ->and($rows[0]['limits'][1])->toBe(['metric' => 'tokens', 'period' => 'day', 'limit_value' => 1_000_000, 'used' => 4_500]);
});

test('rateLimitStatus matches dated model suffixes and skips models without limits', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 13:45:30', 'UTC'));

    seedLimitedPrice(['provider' => 'anthropic', 'model' => 'claude-x'], [
        ['metric' => 'requests', 'period' => 'hour', 'limit_value' => 50],
    ]);
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => 'no-limits-model']);

    seedLimitedUsage('anthropic', 'claude-x-2026-01-01', 100, 100, CarbonImmutable::now('UTC')->subMinutes(10));

    $rows = app(AiUsageReporting::class)->rateLimitStatus();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['model'])->toBe('claude-x')
        ->and($rows[0]['limits'][0]['used'])->toBe(1);
});

test('rateLimitStatus returns empty array when no limits are configured', function (): void {
    AiModelPrice::factory()->create();

    expect(app(AiUsageReporting::class)->rateLimitStatus())->toBe([]);
});
