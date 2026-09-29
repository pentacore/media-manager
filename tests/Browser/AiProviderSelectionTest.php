<?php

declare(strict_types=1);

use App\Enums\OpenRouterSort;
use App\Models\AiModelPrice;
use App\Models\User;
use App\Settings\AiSettings;
use App\Settings\OpenRouterSettings;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.providers.openrouter.key', 'sk-or-test');
    AiModelPrice::factory()->create(['provider' => 'openai', 'model' => resolve(AiSettings::class)->model()]);
    AiModelPrice::factory()->create(['provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet-5']);
});

test('admin can route chat through an OpenRouter model', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-settings.index', absolute: false))
        ->assertNoSmoke()
        ->click('[data-chat-model] button')
        ->click('anthropic/claude-sonnet-5')
        ->click('Save settings')
        ->assertSee('AI settings updated.')
        ->assertSeeIn('[data-chat-model]', 'anthropic/claude-sonnet-5');

    expect(resolve(AiSettings::class)->chatSelection()->toArray())
        ->toBe(['provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet-5']);
});

test('admin can save OpenRouter routing preferences', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-settings.index', absolute: false))
        ->assertNoSmoke()
        ->assertVisible('[data-openrouter-settings]')
        ->click('[data-openrouter-deny-data-collection]')
        ->fill('openrouter_order', 'anthropic, amazon-bedrock')
        ->click('Save settings')
        ->assertSee('AI settings updated.')
        ->assertValue('openrouter_order', 'anthropic, amazon-bedrock');

    $openRouterSettings = resolve(OpenRouterSettings::class);

    expect($openRouterSettings->denyDataCollection())->toBeTrue()
        ->and($openRouterSettings->order())->toBe(['anthropic', 'amazon-bedrock'])
        ->and($openRouterSettings->sort())->not->toBe(OpenRouterSort::Price);
});
