<?php

declare(strict_types=1);

use App\Models\AiModelPrice;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
    Http::fake([
        'openrouter.ai/api/v1/models' => Http::response((string) file_get_contents(base_path('tests/Fixtures/OpenRouter/models.json'))),
    ]);
});

test('admin can add an OpenRouter model from the picker', function (): void {
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
    config()->set('mediamanager.ai.pricing.openrouter.enabled', false);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-prices.index', absolute: false))
        ->assertNoSmoke()
        ->click('[data-openrouter-picker]')
        ->assertSeeIn('[data-openrouter-picker-dialog]', 'prices will not refresh automatically');
});
