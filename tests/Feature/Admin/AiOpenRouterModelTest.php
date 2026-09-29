<?php

declare(strict_types=1);

use App\Enums\PricingSource;
use App\Models\AiModelPrice;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
    config()->set('inertia.ssr.enabled', false);
    Http::preventStrayRequests();
});

/**
 * Fakes OpenRouter's models feed with the shared fixture. Kept out of
 * beforeEach and called per test: the "unreachable" test registers its own
 * failing fake instead, and Http::fake() stubs match in registration order
 * (first registered wins), so a shared success fake in beforeEach would
 * always win over a later per-test override.
 */
function fakeOpenRouterModelsFeed(): void
{
    Http::fake([
        'openrouter.ai/api/v1/models' => Http::response((string) file_get_contents(base_path('tests/Fixtures/OpenRouter/models.json'))),
    ]);
}

test('admins list importable OpenRouter text models without variants', function (): void {
    fakeOpenRouterModelsFeed();

    AiModelPrice::factory()->create(['provider' => 'openrouter', 'model' => 'anthropic/claude-haiku-6']);

    $response = $this->actingAs(User::factory()->admin()->create())
        ->getJson(route('admin.ai-prices.openrouter-models.index'))
        ->assertOk();

    $ids = collect($response->json('models'))->pluck('id');

    expect($ids)->toContain('anthropic/claude-opus-5.5', 'openai/gpt-6-luna', 'anthropic/claude-haiku-6')
        ->not->toContain('anthropic/claude-opus-5.5:batch', 'meta-llama/llama-5-8b:free', 'google/imagen-5');
    expect(collect($response->json('models'))->firstWhere('id', 'anthropic/claude-haiku-6')['added'])->toBeTrue()
        ->and(collect($response->json('models'))->firstWhere('id', 'anthropic/claude-opus-5.5'))->toMatchArray([
            'input_per_mtok' => '4',
            'output_per_mtok' => '20',
            'added' => false,
        ]);
});

test('non-admins cannot list OpenRouter models', function (): void {
    $this->actingAs(User::factory()->member()->create())
        ->getJson(route('admin.ai-prices.openrouter-models.index'))
        ->assertForbidden();
});

test('an unreachable OpenRouter API returns a JSON error', function (): void {
    Http::fake(['openrouter.ai/api/v1/models' => Http::response('down', 503)]);

    $this->actingAs(User::factory()->admin()->create())
        ->getJson(route('admin.ai-prices.openrouter-models.index'))
        ->assertStatus(502)
        ->assertJsonPath('message', fn (string $message): bool => $message !== '');
});

test('importing creates auto-updating OpenRouter price rows', function (): void {
    fakeOpenRouterModelsFeed();

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.ai-prices.openrouter-models.store'), ['models' => ['anthropic/claude-opus-5.5']])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');

    $aiModelPrice = AiModelPrice::query()->where('provider', 'openrouter')->where('model', 'anthropic/claude-opus-5.5')->sole();

    expect((float) $aiModelPrice->input_per_mtok)->toBe(4.0)
        ->and((float) $aiModelPrice->output_per_mtok)->toBe(20.0)
        ->and((float) $aiModelPrice->cache_read_per_mtok)->toBe(0.2)
        ->and($aiModelPrice->pricing_source)->toBe(PricingSource::OpenRouter)
        ->and($aiModelPrice->is_price_locked)->toBeFalse();
});

test('importing never overwrites an existing row and ignores unknown ids', function (): void {
    fakeOpenRouterModelsFeed();

    $locked = AiModelPrice::factory()->create([
        'provider' => 'openrouter',
        'model' => 'anthropic/claude-opus-5.5',
        'input_per_mtok' => '9.0000',
        'pricing_source' => PricingSource::Manual,
        'is_price_locked' => true,
    ]);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.ai-prices.openrouter-models.store'), ['models' => ['anthropic/claude-opus-5.5', 'made-up/model', 'meta-llama/llama-5-8b:free']])
        ->assertRedirect();

    expect((float) $locked->refresh()->input_per_mtok)->toBe(9.0)
        ->and($locked->pricing_source)->toBe(PricingSource::Manual)
        ->and(AiModelPrice::query()->where('provider', 'openrouter')->count())->toBe(1);
});

test('an unreachable OpenRouter API on import flashes an error toast and creates no rows', function (): void {
    Http::fake(['openrouter.ai/api/v1/models' => Http::response('down', 503)]);

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.ai-prices.openrouter-models.store'), ['models' => ['anthropic/claude-opus-5.5']])
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast.type', 'error')
        ->assertSessionHas(
            'inertia.flash_data.toast.message',
            'OpenRouter is unreachable — no models were added.',
        );

    expect(AiModelPrice::query()->where('provider', 'openrouter')->count())->toBe(0);
});

test('importing requires at least one model', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.ai-prices.openrouter-models.store'), ['models' => []])
        ->assertSessionHasErrors('models');
});

test('the prices page tells the picker whether OpenRouter pricing refreshes', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai-prices.index'))
        ->assertInertia(fn ($page) => $page->has('openrouter_pricing_enabled'));
});
