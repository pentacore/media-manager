<?php

declare(strict_types=1);

use App\Models\AiModelPrice;
use App\Services\AiUsage\AiModelRateLimitExceededException;
use App\Services\AiUsage\AiRateLimitGuard;
use App\Settings\AiSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Exceptions\RateLimitedException;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 13:45:30', 'UTC'));
    resolve(AiSettings::class)->setRateLimitsEnforced(true);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param  array<string, mixed>  $attributes
 * @param  array<int, array<string, mixed>>  $limits
 */
function guardLimitedPrice(array $attributes = [], array $limits = []): AiModelPrice
{
    $price = AiModelPrice::factory()->create($attributes);
    $price->rateLimits()->createMany($limits);

    return $price;
}

function guardUsage(string $provider, string $model, int $prompt, int $completion, CarbonImmutable $at): void
{
    // DB insert, not Eloquent create: created_at is not fillable and an
    // Eloquent create would silently stamp "now", breaking window tests.
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

test('enforce throws once the request count inside the rolling window reaches the limit', function (): void {
    guardLimitedPrice(['provider' => 'openai', 'model' => 'gpt-5-mini'], [
        ['metric' => 'requests', 'period' => 'minute', 'limit_value' => 2],
    ]);
    $now = CarbonImmutable::now();
    guardUsage('openai', 'gpt-5-mini', 10, 5, $now->subSeconds(10));
    guardUsage('openai', 'gpt-5-mini', 10, 5, $now->subSeconds(40));

    resolve(AiRateLimitGuard::class)->enforce('openai', 'gpt-5-mini');
})->throws(AiModelRateLimitExceededException::class);

test('the exception is failoverable so the SDK can retry on the failover provider', function (): void {
    expect(is_subclass_of(AiModelRateLimitExceededException::class, RateLimitedException::class))->toBeTrue();
});

test('enforce passes while the request count is below the limit', function (): void {
    guardLimitedPrice(['provider' => 'openai', 'model' => 'gpt-5-mini'], [
        ['metric' => 'requests', 'period' => 'minute', 'limit_value' => 2],
    ]);
    guardUsage('openai', 'gpt-5-mini', 10, 5, CarbonImmutable::now()->subSeconds(10));

    resolve(AiRateLimitGuard::class)->enforce('openai', 'gpt-5-mini');
})->throwsNoExceptions();

test('usage that fell out of the rolling window no longer counts', function (): void {
    guardLimitedPrice(['provider' => 'openai', 'model' => 'gpt-5-mini'], [
        ['metric' => 'requests', 'period' => 'minute', 'limit_value' => 1],
    ]);
    guardUsage('openai', 'gpt-5-mini', 10, 5, CarbonImmutable::now()->subSeconds(61));

    resolve(AiRateLimitGuard::class)->enforce('openai', 'gpt-5-mini');
})->throwsNoExceptions();

test('enforce throws once prompt plus completion tokens reach a token limit', function (): void {
    guardLimitedPrice(['provider' => 'openai', 'model' => 'gpt-5-mini'], [
        ['metric' => 'tokens', 'period' => 'hour', 'limit_value' => 1_000],
    ]);
    $now = CarbonImmutable::now();
    guardUsage('openai', 'gpt-5-mini', 400, 200, $now->subMinutes(5));
    guardUsage('openai', 'gpt-5-mini', 300, 100, $now->subMinutes(50));

    resolve(AiRateLimitGuard::class)->enforce('openai', 'gpt-5-mini');
})->throws(AiModelRateLimitExceededException::class);

test('a dated model id counts against its base catalog row', function (): void {
    guardLimitedPrice(['provider' => 'anthropic', 'model' => 'claude-sonnet-4'], [
        ['metric' => 'requests', 'period' => 'day', 'limit_value' => 1],
    ]);
    guardUsage('anthropic', 'claude-sonnet-4-2025-05-14', 10, 5, CarbonImmutable::now()->subHours(3));

    resolve(AiRateLimitGuard::class)->enforce('anthropic', 'claude-sonnet-4-2025-05-14');
})->throws(AiModelRateLimitExceededException::class);

test('a model without configured limits is never blocked', function (): void {
    guardLimitedPrice(['provider' => 'openai', 'model' => 'gpt-5-mini']);
    guardUsage('openai', 'gpt-5-mini', 10, 5, CarbonImmutable::now()->subSeconds(10));

    resolve(AiRateLimitGuard::class)->enforce('openai', 'gpt-5-mini');
})->throwsNoExceptions();

test('enforce is a no-op while enforcement is switched off', function (): void {
    resolve(AiSettings::class)->setRateLimitsEnforced(false);
    guardLimitedPrice(['provider' => 'openai', 'model' => 'gpt-5-mini'], [
        ['metric' => 'requests', 'period' => 'minute', 'limit_value' => 1],
    ]);
    guardUsage('openai', 'gpt-5-mini', 10, 5, CarbonImmutable::now()->subSeconds(10));

    resolve(AiRateLimitGuard::class)->enforce('openai', 'gpt-5-mini');
})->throwsNoExceptions();

test('the exception names the model, the exhausted limit and the usage', function (): void {
    guardLimitedPrice(['provider' => 'openai', 'model' => 'gpt-5-mini'], [
        ['metric' => 'requests', 'period' => 'minute', 'limit_value' => 1],
    ]);
    guardUsage('openai', 'gpt-5-mini', 10, 5, CarbonImmutable::now()->subSeconds(10));

    try {
        resolve(AiRateLimitGuard::class)->enforce('openai', 'gpt-5-mini');
        $this->fail('Expected the guard to throw.');
    } catch (AiModelRateLimitExceededException $aiModelRateLimitExceededException) {
        expect($aiModelRateLimitExceededException->provider)->toBe('openai')
            ->and($aiModelRateLimitExceededException->model)->toBe('gpt-5-mini')
            ->and($aiModelRateLimitExceededException->metric->value)->toBe('requests')
            ->and($aiModelRateLimitExceededException->period->value)->toBe('minute')
            ->and($aiModelRateLimitExceededException->limitValue)->toBe(1)
            ->and($aiModelRateLimitExceededException->used)->toBe(1)
            ->and($aiModelRateLimitExceededException->getMessage())
            ->toBe('Rate limit reached for openai/gpt-5-mini: 1 of 1 requests per minute used.');
    }
});
