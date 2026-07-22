<?php

declare(strict_types=1);

use App\Services\AiUsage\Pricing\ModelsDevPricingClient;
use App\Services\AiUsage\Pricing\ModelsDevTransportException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

test('models.dev pricing source is disabled by default', function (): void {
    expect(config('mediamanager.ai.pricing.models_dev.enabled'))->toBeFalse();
});

test('models.dev pricing url defaults to the public api endpoint', function (): void {
    expect(config('mediamanager.ai.pricing.models_dev.url'))->toBe('https://models.dev/api.json');
});

test('models.dev pricing client uses a ten second connection timeout', function (): void {
    expect(config('mediamanager.ai.pricing.models_dev.connect_timeout'))->toBe(10);
});

test('models.dev pricing client uses a thirty second request timeout', function (): void {
    expect(config('mediamanager.ai.pricing.models_dev.timeout'))->toBe(30);
});

test('models.dev pricing client retries twice', function (): void {
    expect(config('mediamanager.ai.pricing.models_dev.retries'))->toBe(2);
});

test('models.dev pricing client caps the response at ten megabytes', function (): void {
    expect(config('mediamanager.ai.pricing.models_dev.max_response_bytes'))->toBe(10_000_000);
});

test('pricing anomaly policy allows a four times increase', function (): void {
    expect(config('mediamanager.ai.pricing.max_increase_ratio'))->toBe(4.0);
});

test('pricing anomaly policy allows a quarter times decrease', function (): void {
    expect(config('mediamanager.ai.pricing.min_decrease_ratio'))->toBe(0.25);
});

test('no providers are ignored by default', function (): void {
    expect(config('mediamanager.ai.pricing.ignored_providers'))->toBe([]);
});

test('pricing provider map canonicalizes upstream identifiers', function (): void {
    expect(config('mediamanager.ai.pricing.providers'))->toBe([
        'openai' => 'openai',
        'anthropic' => 'anthropic',
        'google' => 'gemini',
        'xai' => 'xai',
        'deepseek' => 'deepseek',
        'mistral' => 'mistral',
        'groq' => 'groq',
        'cohere' => 'cohere',
        'openrouter' => 'openrouter',
    ]);
});

test('pricing provider map maps google to the gemini driver', function (): void {
    expect(config('mediamanager.ai.pricing.providers.google'))->toBe('gemini');
});

/**
 * @return string Raw contents of a Models.dev fixture file.
 */
function modelsDevFixture(string $name): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/ModelsDev/'.$name));
}

test('fetch returns the decoded top-level provider map on success', function (): void {
    Sleep::fake();

    Http::fake([
        'models.dev/*' => Http::response(modelsDevFixture('api.json'), 200),
    ]);

    $map = new ModelsDevPricingClient()->fetch();

    expect($map)->toBeArray()
        ->and(array_is_list($map))->toBeFalse()
        ->and($map)->toHaveKeys(['openai', 'anthropic', 'google', 'vertex', 'openrouter'])
        ->and($map['openai']['models'])->toHaveKey('gpt-4o');

    Http::assertSentCount(1);
});

test('fetch sends json accept and an application user agent', function (): void {
    Sleep::fake();

    Http::fake([
        'models.dev/*' => Http::response('{"openai":{"models":{}}}', 200),
    ]);

    new ModelsDevPricingClient()->fetch();

    Http::assertSent(fn ($request): bool => $request->hasHeader('Accept', 'application/json')
        && str_starts_with((string) $request->header('User-Agent')[0], 'MediaManager/'));
});

test('fetch retries a transient server error and returns the eventual success', function (): void {
    Sleep::fake();
    config(['mediamanager.ai.pricing.models_dev.retries' => 2]);

    Http::fake([
        'models.dev/*' => Http::sequence()
            ->push('upstream boom', 500)
            ->push(modelsDevFixture('api.json'), 200),
    ]);

    $map = new ModelsDevPricingClient()->fetch();

    expect($map)->toHaveKey('openai');
    Http::assertSentCount(2);
});

test('fetch throws a classified server error after exhausting retries without leaking the body', function (): void {
    Sleep::fake();
    config(['mediamanager.ai.pricing.models_dev.retries' => 2]);

    Http::fake([
        'models.dev/*' => Http::response('SECRET-UPSTREAM-BODY', 500),
    ]);

    $caught = null;

    try {
        new ModelsDevPricingClient()->fetch();
    } catch (ModelsDevTransportException $modelsDevTransportException) {
        $caught = $modelsDevTransportException;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->category)->toBe(ModelsDevTransportException::CATEGORY_SERVER_ERROR)
        ->and($caught->getMessage())->not->toContain('SECRET-UPSTREAM-BODY');

    Http::assertSentCount(3);
});

test('fetch retries a rate limited response and classifies it when persistent', function (): void {
    Sleep::fake();
    config(['mediamanager.ai.pricing.models_dev.retries' => 2]);

    Http::fake([
        'models.dev/*' => Http::response('slow down', 429),
    ]);

    $caught = null;

    try {
        new ModelsDevPricingClient()->fetch();
    } catch (ModelsDevTransportException $modelsDevTransportException) {
        $caught = $modelsDevTransportException;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->category)->toBe(ModelsDevTransportException::CATEGORY_RATE_LIMITED);

    Http::assertSentCount(3);
});

test('fetch does not retry a deterministic client error', function (): void {
    Sleep::fake();
    config(['mediamanager.ai.pricing.models_dev.retries' => 2]);

    Http::fake([
        'models.dev/*' => Http::response('not found', 404),
    ]);

    $caught = null;

    try {
        new ModelsDevPricingClient()->fetch();
    } catch (ModelsDevTransportException $modelsDevTransportException) {
        $caught = $modelsDevTransportException;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->category)->toBe(ModelsDevTransportException::CATEGORY_CLIENT_ERROR);

    Http::assertSentCount(1);
});

test('fetch classifies a connection failure', function (): void {
    Sleep::fake();

    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to models.dev'));

    $caught = null;

    try {
        new ModelsDevPricingClient()->fetch();
    } catch (ModelsDevTransportException $modelsDevTransportException) {
        $caught = $modelsDevTransportException;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->category)->toBe(ModelsDevTransportException::CATEGORY_CONNECTION);
});

test('fetch classifies a timeout distinctly from a plain connection failure', function (): void {
    Sleep::fake();

    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30000 ms'));

    $caught = null;

    try {
        new ModelsDevPricingClient()->fetch();
    } catch (ModelsDevTransportException $modelsDevTransportException) {
        $caught = $modelsDevTransportException;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->category)->toBe(ModelsDevTransportException::CATEGORY_TIMEOUT);
});

test('fetch rejects an oversized body before attempting to decode it', function (): void {
    Sleep::fake();
    config(['mediamanager.ai.pricing.models_dev.max_response_bytes' => 32]);

    // 64 bytes of non-JSON: proves the size guard runs before json_decode.
    Http::fake([
        'models.dev/*' => Http::response(str_repeat('a', 64), 200),
    ]);

    $caught = null;

    try {
        new ModelsDevPricingClient()->fetch();
    } catch (ModelsDevTransportException $modelsDevTransportException) {
        $caught = $modelsDevTransportException;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->category)->toBe(ModelsDevTransportException::CATEGORY_OVERSIZED);

    Http::assertSentCount(1);
});

test('fetch rejects invalid json without retrying', function (): void {
    Sleep::fake();
    config(['mediamanager.ai.pricing.models_dev.retries' => 2]);

    Http::fake([
        'models.dev/*' => Http::response(modelsDevFixture('api-malformed.json'), 200),
    ]);

    $caught = null;

    try {
        new ModelsDevPricingClient()->fetch();
    } catch (ModelsDevTransportException $modelsDevTransportException) {
        $caught = $modelsDevTransportException;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->category)->toBe(ModelsDevTransportException::CATEGORY_INVALID_JSON);

    Http::assertSentCount(1);
});

test('fetch rejects a list-shaped top level payload without retrying', function (): void {
    Sleep::fake();
    config(['mediamanager.ai.pricing.models_dev.retries' => 2]);

    Http::fake([
        'models.dev/*' => Http::response('[{"id":"openai"},{"id":"anthropic"}]', 200),
    ]);

    $caught = null;

    try {
        new ModelsDevPricingClient()->fetch();
    } catch (ModelsDevTransportException $modelsDevTransportException) {
        $caught = $modelsDevTransportException;
    }

    expect($caught)->not->toBeNull()
        ->and($caught->category)->toBe(ModelsDevTransportException::CATEGORY_INVALID_SHAPE);

    Http::assertSentCount(1);
});
