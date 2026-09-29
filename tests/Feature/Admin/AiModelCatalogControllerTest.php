<?php

declare(strict_types=1);

use App\Enums\PricingSource;
use App\Models\AiModelPrice;
use App\Models\User;
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

function catalogPickerControllerFake(int $status = 200): void
{
    Http::fake([
        'openrouter.ai/*' => $status === 200
            ? Http::response((string) file_get_contents(base_path('tests/Fixtures/OpenRouter/models.json')))
            : Http::response('failure', $status),
    ]);
}

test('guests and members cannot read the catalog', function (): void {
    $this->getJson(route('admin.ai-prices.catalog.index', 'openrouter'))->assertUnauthorized();

    $this->actingAs(User::factory()->member()->create())
        ->getJson(route('admin.ai-prices.catalog.index', 'openrouter'))
        ->assertForbidden();
});

test('admin reads the addable catalog models as json', function (): void {
    catalogPickerControllerFake();
    AiModelPrice::factory()->create(['provider' => 'openrouter', 'model' => 'anthropic/claude-haiku-6']);

    $this->actingAs(User::factory()->admin()->create())
        ->getJson(route('admin.ai-prices.catalog.index', 'openrouter'))
        ->assertOk()
        ->assertJsonCount(2, 'models')
        ->assertJsonPath('models.0.model', 'anthropic/claude-opus-5.5')
        ->assertJsonPath('models.0.source', 'openrouter')
        ->assertJsonPath('models.0.prices.input_per_mtok', '4.0000')
        ->assertJsonPath('models.1.tiered', true)
        ->assertJsonPath('covered', true);
});

test('a provider no enabled feed covers returns an empty uncovered list', function (): void {
    catalogPickerControllerFake();

    $this->actingAs(User::factory()->admin()->create())
        ->getJson(route('admin.ai-prices.catalog.index', 'anthropic'))
        ->assertOk()
        ->assertJsonCount(0, 'models')
        ->assertJsonPath('covered', false);
});

test('a provider missing during a partial outage returns a 503', function (): void {
    config()->set('mediamanager.ai.pricing.models_dev.enabled', true);

    Http::fake([
        'openrouter.ai/*' => Http::response((string) file_get_contents(base_path('tests/Fixtures/OpenRouter/models.json'))),
        'models.dev/*' => Http::response('failure', 503),
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->getJson(route('admin.ai-prices.catalog.index', 'anthropic'))
        ->assertStatus(503)
        ->assertJsonStructure(['message']);
});

test('an unsupported provider is not found', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->getJson('/admin/ai-prices/catalog/not-a-provider')
        ->assertNotFound();
});

test('an ignored provider is not found', function (): void {
    resolve(AiSettings::class)->setIgnoredPricingProviders(['openrouter']);

    $this->actingAs(User::factory()->admin()->create())
        ->getJson(route('admin.ai-prices.catalog.index', 'openrouter'))
        ->assertNotFound();
});

test('a failed catalog returns a 503 with a message', function (): void {
    catalogPickerControllerFake(503);

    $this->actingAs(User::factory()->admin()->create())
        ->getJson(route('admin.ai-prices.catalog.index', 'openrouter'))
        ->assertStatus(503)
        ->assertJsonStructure(['message']);
});

test('bulk add creates synced rows with catalog prices, ignoring prices in the request', function (): void {
    catalogPickerControllerFake();
    resolve(AiSettings::class)->setAutoCreatePricingProviders(['openai']);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.ai-prices.catalog.store'), [
            'provider' => 'openrouter',
            'models' => ['anthropic/claude-opus-5.5', 'openai/gpt-6-luna'],
            'input_per_mtok' => 999,
        ])
        ->assertRedirect(route('admin.ai-prices.index'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'success')
        ->assertSessionHas('inertia.flash_data.toast.message', 'Added 2 models.');

    $aiModelPrice = AiModelPrice::query()->where('provider', 'openrouter')->where('model', 'anthropic/claude-opus-5.5')->sole();

    expect($aiModelPrice->input_per_mtok)->toBe('4.0000')
        ->and($aiModelPrice->output_per_mtok)->toBe('20.0000')
        ->and($aiModelPrice->pricing_source)->toBe(PricingSource::OpenRouter)
        ->and($aiModelPrice->is_price_locked)->toBeFalse()
        ->and($aiModelPrice->pricing_synced_at)->not->toBeNull()
        ->and(AiModelPrice::query()->where('provider', 'openrouter')->count())->toBe(2);
});

test('bulk add skips existing and vanished models and leaves existing rows untouched', function (): void {
    catalogPickerControllerFake();
    $existing = AiModelPrice::factory()->create([
        'provider' => 'openrouter',
        'model' => 'anthropic/claude-opus-5.5',
        'input_per_mtok' => 1.5,
        'is_price_locked' => false,
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.ai-prices.catalog.store'), [
            'provider' => 'openrouter',
            'models' => ['anthropic/claude-opus-5.5', 'vendor/gone', 'anthropic/claude-haiku-6'],
        ])
        ->assertRedirect(route('admin.ai-prices.index'))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Added 1 of 3 models; 2 were already added or are no longer in the catalog.');

    expect($existing->fresh()->input_per_mtok)->toBe('1.5000')
        ->and(AiModelPrice::query()->where('model', 'vendor/gone')->exists())->toBeFalse()
        ->and(AiModelPrice::query()->where('model', 'anthropic/claude-haiku-6')->exists())->toBeTrue();
});

test('bulk add with the catalog down writes nothing and flashes an error', function (): void {
    catalogPickerControllerFake(503);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.ai-prices.catalog.store'), [
            'provider' => 'openrouter',
            'models' => ['anthropic/claude-opus-5.5'],
        ])
        ->assertRedirect(route('admin.ai-prices.index'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');

    expect(AiModelPrice::query()->where('provider', 'openrouter')->exists())->toBeFalse();
});

test('bulk add validates provider and models', function (array $payload, string $field): void {
    resolve(AiSettings::class)->setIgnoredPricingProviders(['groq']);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.ai-prices.catalog.store'), $payload)
        ->assertSessionHasErrors($field);
})->with([
    'missing provider' => [['models' => ['a']], 'provider'],
    'unsupported provider' => [['provider' => 'nope', 'models' => ['a']], 'provider'],
    'ignored provider' => [['provider' => 'groq', 'models' => ['a']], 'provider'],
    'no models' => [['provider' => 'openrouter', 'models' => []], 'models'],
    'too many models' => [['provider' => 'openrouter', 'models' => array_map(fn (int $i): string => 'm'.$i, range(1, 101))], 'models'],
    'duplicate models' => [['provider' => 'openrouter', 'models' => ['a', 'a']], 'models.0'],
]);
