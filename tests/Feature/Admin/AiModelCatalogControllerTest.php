<?php

declare(strict_types=1);

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
        ->assertJsonPath('models.1.tiered', true);
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
