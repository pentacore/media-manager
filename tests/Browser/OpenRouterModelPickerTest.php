<?php

declare(strict_types=1);

use App\Models\AiModelPrice;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

/**
 * Fakes OpenRouter's models feed with the shared fixture. Kept out of
 * beforeEach and called per test, like
 * tests/Feature/Admin/AiOpenRouterModelTest.php's helper of the same name:
 * Http::fake() stubs match in registration order (first registered wins), so
 * a shared fake in beforeEach would always win over a later per-test
 * override or sequence.
 */
function fakeOpenRouterModelsFeed(): void
{
    Http::fake([
        'openrouter.ai/api/v1/models' => Http::response((string) file_get_contents(base_path('tests/Fixtures/OpenRouter/models.json'))),
    ]);
}

test('admin can add an OpenRouter model from the picker', function (): void {
    fakeOpenRouterModelsFeed();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-prices.index', absolute: false))
        ->assertNoSmoke()
        ->click('[data-openrouter-picker]')
        ->assertVisible('[data-openrouter-picker-dialog]')
        ->fill('openrouter_model_search', 'opus')
        ->assertSeeIn('[data-openrouter-picker-dialog]', 'anthropic/claude-opus-5.5')
        ->assertDontSeeIn('[data-openrouter-picker-dialog]', 'openai/gpt-6-luna')
        ->click('[data-openrouter-model="anthropic/claude-opus-5.5"]')
        ->click('[data-openrouter-picker-submit]')
        ->assertSee('1 OpenRouter model added.');

    expect(AiModelPrice::query()->where('provider', 'openrouter')->where('model', 'anthropic/claude-opus-5.5')->exists())->toBeTrue();
});

test('the picker warns when OpenRouter pricing does not refresh', function (): void {
    fakeOpenRouterModelsFeed();
    config()->set('mediamanager.ai.pricing.openrouter.enabled', false);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-prices.index', absolute: false))
        ->assertNoSmoke()
        ->click('[data-openrouter-picker]')
        ->assertSeeIn('[data-openrouter-picker-dialog]', 'prices will not refresh automatically');
});

test('the picker stays open and keeps the selection when the import fails', function (): void {
    // OpenRouterModelImporter caches the fetched feed for 10 minutes, so the
    // picker's GET and the submit's POST would otherwise share one cached
    // fetch and both succeed. A sequence gives the GET a success response and
    // the POST's re-fetch (once the cache is flushed below) a failure — and
    // it must be the only fake registered for this URL in this test, since a
    // later Http::fake() for the same URL never overrides an earlier one.
    Http::fakeSequence('openrouter.ai/api/v1/models')
        ->pushFile(base_path('tests/Fixtures/OpenRouter/models.json'))
        ->pushStatus(503);

    $this->actingAs(User::factory()->admin()->create());

    $pendingAwaitablePage = visit(route('admin.ai-prices.index', absolute: false));
    $pendingAwaitablePage->assertNoSmoke()
        ->click('[data-openrouter-picker]')
        ->assertVisible('[data-openrouter-picker-dialog]')
        ->click('[data-openrouter-model="anthropic/claude-opus-5.5"]');

    // Force the POST's import() to re-fetch instead of reusing the GET's
    // now-cached model list, so it genuinely hits the second, failing fake.
    Cache::flush();

    $pendingAwaitablePage->click('[data-openrouter-picker-submit]')
        ->assertSee('OpenRouter is unreachable — no models were added.')
        ->assertVisible('[data-openrouter-picker-dialog]');

    expect(AiModelPrice::query()->where('provider', 'openrouter')->exists())->toBeFalse();
});
