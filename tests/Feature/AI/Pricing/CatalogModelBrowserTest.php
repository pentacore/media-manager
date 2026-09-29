<?php

declare(strict_types=1);

use App\Enums\PricingSource;
use App\Models\AiModelPrice;
use App\Services\AiUsage\Pricing\CatalogModelBrowser;
use App\Services\AiUsage\Pricing\CatalogUnavailableException;
use App\Services\AiUsage\Pricing\Data\CatalogModelOption;
use App\Settings\AiSettings;
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

test('a provider no source covers returns an empty list', function (): void {
    catalogPickerFakeOpenRouter();

    expect(resolve(CatalogModelBrowser::class)->available('cohere'))->toBe([]);
});

test('addableCandidate returns the candidate only while the model has no row', function (): void {
    catalogPickerFakeOpenRouter();
    $catalogModelBrowser = resolve(CatalogModelBrowser::class);

    $candidate = $catalogModelBrowser->addableCandidate('openrouter', 'anthropic/claude-opus-5.5');

    expect($candidate?->source)->toBe(PricingSource::OpenRouter)
        ->and($candidate?->fields['input_per_mtok']->value)->toBe('4.0000')
        ->and($catalogModelBrowser->addableCandidate('openrouter', 'vendor/missing'))->toBeNull();

    AiModelPrice::factory()->create(['provider' => 'openrouter', 'model' => 'anthropic/claude-opus-5.5']);

    expect($catalogModelBrowser->addableCandidate('openrouter', 'anthropic/claude-opus-5.5'))->toBeNull();
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

test('catalogAttributes returns null instead of throwing when the catalog is down', function (): void {
    catalogPickerFakeOpenRouter(503);

    expect(resolve(CatalogModelBrowser::class)->catalogAttributes('openrouter', 'anthropic/claude-opus-5.5', ['input_per_mtok' => 4, 'output_per_mtok' => 20]))
        ->toBeNull();
});

test('providers lists canonical providers without ignored ones', function (): void {
    resolve(AiSettings::class)->setIgnoredPricingProviders(['groq']);

    $providers = resolve(CatalogModelBrowser::class)->providers();

    expect($providers)->toContain('openrouter', 'gemini', 'openai')
        ->and($providers)->not->toContain('groq', 'google')
        ->and($providers)->toBe(array_values(array_unique($providers)));
});
