<?php

declare(strict_types=1);

use App\Enums\OpenRouterSort;
use App\Models\User;
use App\Settings\OpenRouterSettings;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
    config()->set('ai.providers.openrouter.key', 'sk-or-test');
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
