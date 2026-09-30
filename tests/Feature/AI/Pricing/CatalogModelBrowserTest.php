<?php

declare(strict_types=1);

use App\Enums\PricingSource;
use App\Models\AiModelPrice;
use App\Services\AiUsage\Pricing\CatalogModelBrowser;
use App\Services\AiUsage\Pricing\CatalogUnavailableException;
use App\Services\AiUsage\Pricing\Data\CatalogModelOption;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    Sleep::fake();

    foreach (['models_dev', 'litellm', 'openrouter', 'xai'] as $source) {
        config()->set(sprintf('mediamanager.ai.pricing.%s.enabled', $source), false);
        config()->set(sprintf('mediamanager.ai.pricing.%s.retries', $source), 0);
    }

    config()->set('mediamanager.ai.pricing.openrouter.enabled', true);
});

function catalogPickerFakeOpenRouter(int $status = 200): void
{
    Http::fake([
        'openrouter.ai/*' => $status === 200
            ? Http::response((string) file_get_contents(base_path('tests/Fixtures/OpenRouter/models.json')))
            : Http::response('failure', $status),
    ]);
}

/**
 * @param  list<CatalogModelOption>  $options
 * @return list<string>
 */
function catalogPickerModels(array $options): array
{
    return array_map(fn (CatalogModelOption $catalogModelOption): string => $catalogModelOption->model, $options);
}

test('available lists the provider catalog sorted by model with prices and tier flags', function (): void {
    catalogPickerFakeOpenRouter();

    $options = resolve(CatalogModelBrowser::class)->available('openrouter');

    expect(catalogPickerModels($options))->toBe(['anthropic/claude-haiku-6', 'anthropic/claude-opus-5.5', 'openai/gpt-6-luna']);

    $opus = $options[1]->toArray();

    expect($opus['source'])->toBe(PricingSource::OpenRouter->value)
        ->and($opus['tiered'])->toBeFalse()
        ->and($opus['prices']['input_per_mtok'])->toBe('4.0000')
        ->and($opus['prices']['output_per_mtok'])->toBe('20.0000')
        ->and($opus['prices']['batch_input_per_mtok'])->toBeNull()
        ->and($options[2]->tiered)->toBeTrue();
});

test('models that already have a row are excluded, even while the cache is warm', function (): void {
    catalogPickerFakeOpenRouter();
    $catalogModelBrowser = resolve(CatalogModelBrowser::class);

    expect(catalogPickerModels($catalogModelBrowser->available('openrouter')))->toContain('anthropic/claude-opus-5.5');

    AiModelPrice::factory()->create(['provider' => 'openrouter', 'model' => 'anthropic/claude-opus-5.5']);

    expect(catalogPickerModels($catalogModelBrowser->available('openrouter')))->not->toContain('anthropic/claude-opus-5.5');
    Http::assertSentCount(1);
});

test('an unavailable catalog throws and is not cached', function (): void {
    // A second Http::fake() call appends stubs (the first match wins), so the
    // fail-then-recover order must come from one sequence.
    Http::fake([
        'openrouter.ai/*' => Http::sequence()
            ->push('failure', 503)
            ->push((string) file_get_contents(base_path('tests/Fixtures/OpenRouter/models.json'))),
    ]);

    expect(fn (): array => resolve(CatalogModelBrowser::class)->available('openrouter'))
        ->toThrow(CatalogUnavailableException::class);

    expect(resolve(CatalogModelBrowser::class)->available('openrouter'))->toHaveCount(3);
});

test('a provider no enabled source covers is cached as not covered', function (): void {
    catalogPickerFakeOpenRouter();
    $catalogModelBrowser = resolve(CatalogModelBrowser::class);

    expect($catalogModelBrowser->available('anthropic'))->toBe([])
        ->and($catalogModelBrowser->covers('anthropic'))->toBeFalse()
        ->and($catalogModelBrowser->covers('openrouter'))->toBeTrue();

    // One fetch for anthropic, one for openrouter: the uncovered slice is cached.
    Http::assertSentCount(2);
});

test('a provider missing during a partial outage throws and is not cached', function (): void {
    config()->set('mediamanager.ai.pricing.models_dev.enabled', true);

    Http::fake([
        'openrouter.ai/*' => Http::response((string) file_get_contents(base_path('tests/Fixtures/OpenRouter/models.json'))),
        'models.dev/*' => Http::sequence()
            ->push('failure', 503)
            ->push((string) file_get_contents(base_path('tests/Fixtures/ModelsDev/api.json'))),
    ]);

    expect(fn (): array => resolve(CatalogModelBrowser::class)->available('anthropic'))
        ->toThrow(CatalogUnavailableException::class);

    expect(catalogPickerModels(resolve(CatalogModelBrowser::class)->available('anthropic')))->not->toBe([])
        ->and(resolve(CatalogModelBrowser::class)->covers('anthropic'))->toBeTrue();
});

test('the cache key follows the enabled sources', function (): void {
    catalogPickerFakeOpenRouter();
    $catalogModelBrowser = resolve(CatalogModelBrowser::class);

    expect($catalogModelBrowser->covers('anthropic'))->toBeFalse();

    config()->set('mediamanager.ai.pricing.models_dev.enabled', true);
    Http::fake([
        'models.dev/*' => Http::response((string) file_get_contents(base_path('tests/Fixtures/ModelsDev/api.json'))),
    ]);

    expect($catalogModelBrowser->covers('anthropic'))->toBeTrue();
});

test('a cold fetch waiting on another download gives up with a still-loading error', function (): void {
    Sleep::fake(syncWithCarbon: true);
    catalogPickerFakeOpenRouter();

    $lock = Cache::lock('ai-pricing:catalog-fetch:openrouter', CatalogModelBrowser::FETCH_LOCK_SECONDS);
    expect($lock->get())->toBeTrue();

    expect(fn (): array => resolve(CatalogModelBrowser::class)->available('openrouter'))
        ->toThrow(CatalogUnavailableException::class, 'still loading');

    Http::assertNothingSent();

    $lock->release();

    expect(resolve(CatalogModelBrowser::class)->available('openrouter'))->toHaveCount(3);
});

test('a cold fetch releases the per-provider lock once the slice is cached', function (): void {
    catalogPickerFakeOpenRouter();

    resolve(CatalogModelBrowser::class)->available('openrouter');

    expect(Cache::lock('ai-pricing:catalog-fetch:openrouter', 10)->get())->toBeTrue();
});

test('addableCandidates returns catalog candidates only for models without a row, with one feed fetch', function (): void {
    catalogPickerFakeOpenRouter();
    $catalogModelBrowser = resolve(CatalogModelBrowser::class);

    AiModelPrice::factory()->create(['provider' => 'openrouter', 'model' => 'anthropic/claude-haiku-6']);

    $candidates = $catalogModelBrowser->addableCandidates('openrouter', [
        'anthropic/claude-opus-5.5',
        'vendor/missing',
        'anthropic/claude-haiku-6',
        'openai/gpt-6-luna',
    ]);

    expect(array_keys($candidates))->toBe(['anthropic/claude-opus-5.5', 'openai/gpt-6-luna'])
        ->and($candidates['anthropic/claude-opus-5.5']->source)->toBe(PricingSource::OpenRouter)
        ->and($candidates['anthropic/claude-opus-5.5']->provider)->toBe('openrouter')
        ->and($candidates['anthropic/claude-opus-5.5']->fields['input_per_mtok']->value)->toBe('4.0000');

    Http::assertSentCount(1);
});

test('catalogAttributes returns feed provenance only when the submitted rates match', function (): void {
    catalogPickerFakeOpenRouter();
    $catalogModelBrowser = resolve(CatalogModelBrowser::class);
    $prices = $catalogModelBrowser->available('openrouter')[1]->prices;

    $attributes = $catalogModelBrowser->catalogAttributes('openrouter', 'anthropic/claude-opus-5.5', [
        'input_per_mtok' => 4,
        'output_per_mtok' => '20',
        'cache_read_per_mtok' => $prices['cache_read_per_mtok'],
        'cache_write_per_mtok' => $prices['cache_write_per_mtok'],
        'reasoning_per_mtok' => $prices['reasoning_per_mtok'],
        'search_unit_per_k' => 0,
    ]);

    expect($attributes)->not->toBeNull()
        ->and($attributes['pricing_source'])->toBe(PricingSource::OpenRouter)
        ->and($attributes['pricing_source_url'])->toBe('https://openrouter.ai/api/v1/models')
        ->and($attributes['is_price_locked'])->toBeFalse()
        ->and($attributes)->toHaveKey('pricing_synced_at')
        ->and($attributes)->toHaveKey('batch_input_per_mtok');

    expect($catalogModelBrowser->catalogAttributes('openrouter', 'anthropic/claude-opus-5.5', [
        'input_per_mtok' => 5,
        'output_per_mtok' => 20,
        'cache_read_per_mtok' => $prices['cache_read_per_mtok'],
        'cache_write_per_mtok' => $prices['cache_write_per_mtok'],
        'reasoning_per_mtok' => $prices['reasoning_per_mtok'],
    ]))->toBeNull();
});

test('catalogAttributes on a cold cache returns null without calling a feed', function (): void {
    catalogPickerFakeOpenRouter();

    expect(resolve(CatalogModelBrowser::class)->catalogAttributes('openrouter', 'anthropic/claude-opus-5.5', ['input_per_mtok' => 4, 'output_per_mtok' => 20]))
        ->toBeNull();

    Http::assertNothingSent();
});

test('an xai source failing does not block a provider only xai could never have covered', function (): void {
    config()->set('mediamanager.ai.pricing.openrouter.enabled', false);
    config()->set('mediamanager.ai.pricing.models_dev.enabled', true);
    config()->set('mediamanager.ai.pricing.models_dev.retries', 0);
    config()->set('mediamanager.ai.pricing.xai.enabled', true);
    config()->set('mediamanager.ai.pricing.xai.retries', 0);
    config()->set('ai.providers.xai.key', 'xai-test-key');

    /** @var array<string, mixed> $modelsDev */
    $modelsDev = json_decode((string) file_get_contents(base_path('tests/Fixtures/ModelsDev/api.json')), true, flags: JSON_THROW_ON_ERROR);
    unset($modelsDev['anthropic']);

    Http::fake([
        'api.x.ai/*' => Http::response('failure', 401),
        'models.dev/*' => Http::response(json_encode($modelsDev, JSON_THROW_ON_ERROR)),
    ]);

    $catalogModelBrowser = resolve(CatalogModelBrowser::class);

    expect($catalogModelBrowser->covers('anthropic'))->toBeFalse();

    // Cached as not covered rather than thrown, so a second read makes no
    // further request.
    expect($catalogModelBrowser->covers('anthropic'))->toBeFalse();
    Http::assertSentCount(2);
});

test('the same failing xai source still blocks the provider it could have covered', function (): void {
    config()->set('mediamanager.ai.pricing.openrouter.enabled', false);
    config()->set('mediamanager.ai.pricing.models_dev.enabled', true);
    config()->set('mediamanager.ai.pricing.models_dev.retries', 0);
    config()->set('mediamanager.ai.pricing.xai.enabled', true);
    config()->set('mediamanager.ai.pricing.xai.retries', 0);
    config()->set('ai.providers.xai.key', 'xai-test-key');

    Http::fake([
        'api.x.ai/*' => Http::response('failure', 401),
        'models.dev/*' => Http::response((string) file_get_contents(base_path('tests/Fixtures/ModelsDev/api.json'))),
    ]);

    expect(fn (): bool => resolve(CatalogModelBrowser::class)->covers('xai'))
        ->toThrow(CatalogUnavailableException::class);
});

test('providers lists canonical providers without ignored ones', function (): void {
    resolve(AiSettings::class)->setIgnoredPricingProviders(['groq']);

    $providers = resolve(CatalogModelBrowser::class)->providers();

    expect($providers)->toContain('openrouter', 'gemini', 'openai')
        ->and($providers)->not->toContain('groq', 'google')
        ->and($providers)->toBe(array_values(array_unique($providers)));
});
