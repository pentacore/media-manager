<?php

declare(strict_types=1);

use App\Models\AiModelPrice;
use App\Models\User;
use App\Settings\AiSettings;

test('admin can enable the structured pricing sources from ai settings', function (): void {
    config()->set('mediamanager.ai.pricing.openrouter.enabled', false);
    config()->set('mediamanager.ai.pricing.litellm.enabled', false);
    config()->set('mediamanager.ai.pricing.xai.enabled', false);
    config()->set('ai.providers.xai.key', null);
    // The model <Select> submits from the pricing catalog; seed one row so the
    // required `model` field posts a value.
    AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
    ]);

    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.ai-settings.index', absolute: false))
        ->assertNoSmoke()
        ->assertSee('LiteLLM cross-check')
        ->assertSee('OpenRouter API')
        ->assertSee('xAI pricing API')
        ->assertSeeIn('[data-xai-pricing-key-missing]', 'No XAI_API_KEY is configured')
        ->click('[data-openrouter-pricing-toggle]')
        ->click('[data-litellm-pricing-toggle]')
        ->assertScript('document.querySelector(\'input[name="openrouter_pricing_enabled"]\').value === "1"')
        ->assertScript('document.querySelector(\'input[name="litellm_pricing_enabled"]\').value === "1"')
        ->click('Save settings')
        ->assertSee('AI settings updated.');

    $aiSettings = resolve(AiSettings::class);

    expect($aiSettings->openRouterPricingEnabled())->toBeTrue()
        ->and($aiSettings->liteLlmPricingEnabled())->toBeTrue()
        ->and($aiSettings->xaiPricingEnabled())->toBeFalse();

    $webpage->assertNoSmoke();
});
