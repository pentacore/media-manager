<?php

declare(strict_types=1);

use App\Enums\AiMode;
use App\Enums\AiTask;
use App\Models\AiTaskModel;
use App\Models\User;
use App\Settings\AiSettings;
use App\Settings\MediaReplacementSettings;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
});

/**
 * Base payload for a valid AI settings update request.
 *
 * @return array<string, string>
 */
function baseAiSettingsPayload(): array
{
    return [
        'mode' => 'executive',
    ];
}

test('guests cannot access AI settings', function (): void {
    $this->get(route('admin.ai-settings.index'))
        ->assertRedirect(route('login'));
});

test('non-admin cannot access AI settings', function (): void {
    $user = User::factory()->member()->create();

    $this->actingAs($user)
        ->get(route('admin.ai-settings.index'))
        ->assertForbidden();
});

test('admin sees current settings on index', function (): void {
    $admin = User::factory()->admin()->create();
    resolve(AiSettings::class)->setMode(AiMode::Advisory);

    $this->actingAs($admin)
        ->get(route('admin.ai-settings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/AiSettings/Index')
            ->where('settings.mode', 'advisory')
            ->has('modes')
            ->missing('settings.model')
            ->missing('settings.title_model')
            ->missing('settings.advisor_reasoning_level')
            ->missing('settings.failover_provider')
            ->missing('unpricedModels')
            ->missing('reasoningLevels')
        );
});

test('admin can update settings', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [
            'mode' => 'advisory',
        ])
        ->assertRedirect(route('admin.ai-settings.index'))
        ->assertSessionHasNoErrors();

    expect(resolve(AiSettings::class)->mode())->toBe(AiMode::Advisory);
});

test('saving AI settings leaves the AI Models selections untouched', function (): void {
    AiTaskModel::factory()->task(AiTask::Chat)->selecting('openrouter', 'anthropic/claude-sonnet-5')->create();
    AiTaskModel::factory()->task(AiTask::Failover)->state(['provider' => 'anthropic'])->create();

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-settings.update'), [
            ...baseAiSettingsPayload(),
            'model' => 'gpt-5-mini',
            'model_provider' => 'openai',
            'failover_provider' => 'none',
        ])
        ->assertRedirect(route('admin.ai-settings.index'))
        ->assertSessionHasNoErrors();

    expect(AiTaskModel::query()->forTask(AiTask::Chat)->first()?->only(['provider', 'model']))
        ->toBe(['provider' => 'openrouter', 'model' => 'anthropic/claude-sonnet-5'])
        ->and(AiTaskModel::query()->forTask(AiTask::Failover)->first()?->provider)->toBe('anthropic');
    $this->assertDatabaseMissing('app_settings', ['key' => 'ai.model']);
});

test('admin can update the chat timeout', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [
            ...baseAiSettingsPayload(),
            'chat_timeout' => 300,
        ])
        ->assertRedirect(route('admin.ai-settings.index'))
        ->assertSessionHasNoErrors();

    expect(resolve(AiSettings::class)->chatTimeout())->toBe(300);
});

test('a blank chat timeout clears the override back to the config default', function (): void {
    $admin = User::factory()->admin()->create();
    config()->set('mediamanager.ai.chat_timeout', 120);
    resolve(AiSettings::class)->setChatTimeout(300);

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [
            ...baseAiSettingsPayload(),
            'chat_timeout' => '',
        ])
        ->assertRedirect(route('admin.ai-settings.index'))
        ->assertSessionHasNoErrors();

    expect(resolve(AiSettings::class)->chatTimeout())->toBe(120);
});

test('update rejects an out-of-range chat timeout', function (string|int $value): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [
            ...baseAiSettingsPayload(),
            'chat_timeout' => $value,
        ])
        ->assertSessionHasErrors('chat_timeout');
})->with([
    'below the floor' => [29],
    'above the ceiling' => [601],
    'not a number' => ['soon'],
]);

test('index exposes the chat timeout', function (): void {
    $admin = User::factory()->admin()->create();
    resolve(AiSettings::class)->setChatTimeout(300);

    $this->actingAs($admin)
        ->get(route('admin.ai-settings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/AiSettings/Index')
            ->where('settings.chat_timeout', 300)
        );
});

test('index exposes pricing sync settings and ignorable providers', function (): void {
    $admin = User::factory()->admin()->create();
    config()->set('mediamanager.ai.pricing.models_dev.enabled', true);

    $this->actingAs($admin)
        ->get(route('admin.ai-settings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/AiSettings/Index')
            ->where('settings.models_dev_pricing_enabled', true)
            ->where('settings.ignored_pricing_providers', [])
            ->where('settings.auto_create_pricing_providers', ['openai', 'anthropic', 'gemini', 'xai', 'deepseek', 'mistral', 'groq', 'cohere'])
            ->has('pricingProviders')
            ->where('pricingProviders.0.value', 'openai')
        );
});

test('admin can toggle the models.dev pricing feed off, overriding the env default', function (): void {
    $admin = User::factory()->admin()->create();
    // Env/config default is ON; the saved setting must win.
    config()->set('mediamanager.ai.pricing.models_dev.enabled', true);

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [
            ...baseAiSettingsPayload(),
            'models_dev_pricing_enabled' => '0',
        ])
        ->assertRedirect(route('admin.ai-settings.index'))
        ->assertSessionHasNoErrors();

    expect(resolve(AiSettings::class)->modelsDevPricingEnabled())->toBeFalse();
});

test('admin can save the ignored pricing providers list', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [
            ...baseAiSettingsPayload(),
            'ignored_pricing_providers' => ['groq', 'cohere'],
        ])
        ->assertRedirect(route('admin.ai-settings.index'))
        ->assertSessionHasNoErrors();

    expect(resolve(AiSettings::class)->ignoredPricingProviders())->toBe(['groq', 'cohere']);
});

test('update rejects an unknown ignored pricing provider', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [
            ...baseAiSettingsPayload(),
            'ignored_pricing_providers' => ['not-a-provider'],
        ])
        ->assertSessionHasErrors('ignored_pricing_providers.0');
});

test('update validates mode is a known value', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [
            'mode' => 'enthusiastic',
        ])
        ->assertSessionHasErrors('mode');
});

test('update requires mode', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [])
        ->assertSessionHasErrors(['mode'])
        ->assertSessionDoesntHaveErrors(['model', 'title_model', 'advisor_reasoning_level']);
});

test('ai settings update no longer accepts media_replacement fields', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [
            ...baseAiSettingsPayload(),
            'media_replacement' => json_encode([
                'automatic_selection_enabled' => true,
                'automatic_selection_threshold' => 42,
                'global_languages' => ['English'],
                'scoped_languages' => ['anime' => null, 'tv' => null, 'movie' => null],
                'season_pack_policy' => 'approval_required',
                'subtitle_check' => [
                    'enabled' => false,
                    'max_attempts_per_target' => 1,
                    'cooldown_hours' => 24,
                ],
                'guidance' => [
                    'anime' => ['notes' => '', 'rules' => []],
                    'tv' => ['notes' => '', 'rules' => []],
                    'movie' => ['notes' => '', 'rules' => []],
                ],
            ], JSON_THROW_ON_ERROR),
        ])
        ->assertRedirect(route('admin.ai-settings.index'))
        ->assertSessionHasNoErrors();

    expect(resolve(MediaReplacementSettings::class)->automaticSelectionThreshold())->toBe(90);
});

test('index exposes whether model rate limits are enforced, defaulting to the config value', function (): void {
    $admin = User::factory()->admin()->create();
    config()->set('mediamanager.ai.rate_limits.enforce', true);

    $this->actingAs($admin)
        ->get(route('admin.ai-settings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/AiSettings/Index')
            ->where('settings.rate_limits_enforced', true)
        );
});

test('admin can turn rate limit enforcement on, overriding the config default', function (): void {
    $admin = User::factory()->admin()->create();
    config()->set('mediamanager.ai.rate_limits.enforce', false);

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [
            ...baseAiSettingsPayload(),
            'rate_limits_enforced' => '1',
        ])
        ->assertRedirect(route('admin.ai-settings.index'))
        ->assertSessionHasNoErrors();

    expect(resolve(AiSettings::class)->rateLimitsEnforced())->toBeTrue();
});

test('omitting the rate limit enforcement field clears the override back to config', function (): void {
    $admin = User::factory()->admin()->create();
    config()->set('mediamanager.ai.rate_limits.enforce', false);
    resolve(AiSettings::class)->setRateLimitsEnforced(true);

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), baseAiSettingsPayload())
        ->assertRedirect(route('admin.ai-settings.index'))
        ->assertSessionHasNoErrors();

    expect(resolve(AiSettings::class)->rateLimitsEnforced())->toBeFalse();
});

test('admin can save the auto-create pricing providers list', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [
            ...baseAiSettingsPayload(),
            // The page always posts a blank placeholder entry first.
            'auto_create_pricing_providers' => ['', 'openai', 'openrouter'],
        ])
        ->assertRedirect(route('admin.ai-settings.index'))
        ->assertSessionHasNoErrors();

    expect(resolve(AiSettings::class)->autoCreatePricingProviders())->toBe(['openai', 'openrouter']);
});

test('an all-unchecked auto-create list saves every provider as update-only', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [
            ...baseAiSettingsPayload(),
            'auto_create_pricing_providers' => [''],
        ])
        ->assertSessionHasNoErrors();

    expect(resolve(AiSettings::class)->autoCreatePricingProviders())->toBe([]);
});

test('omitting the auto-create list leaves the saved setting untouched', function (): void {
    $admin = User::factory()->admin()->create();
    resolve(AiSettings::class)->setAutoCreatePricingProviders(['groq']);

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), baseAiSettingsPayload())
        ->assertSessionHasNoErrors();

    expect(resolve(AiSettings::class)->autoCreatePricingProviders())->toBe(['groq']);
});

test('update rejects an unknown auto-create pricing provider', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [
            ...baseAiSettingsPayload(),
            'auto_create_pricing_providers' => ['', 'not-a-provider'],
        ])
        ->assertSessionHasErrors('auto_create_pricing_providers.0');
});

test('index maps upstream provider spellings to the canonical checkbox values', function (): void {
    $admin = User::factory()->admin()->create();
    config()->set('mediamanager.ai.pricing.auto_create_providers', ['google', 'openai', 'not-a-provider']);
    config()->set('mediamanager.ai.pricing.ignored_providers', ['google']);

    $this->actingAs($admin)
        ->get(route('admin.ai-settings.index'))
        ->assertInertia(fn ($page) => $page
            ->where('settings.auto_create_pricing_providers', ['gemini', 'openai'])
            ->where('settings.ignored_pricing_providers', ['gemini'])
        );
});

test('index exposes the structured pricing source switches', function (): void {
    $admin = User::factory()->admin()->create();
    config()->set('mediamanager.ai.pricing.openrouter.enabled', true);
    config()->set('ai.providers.xai.key');

    $this->actingAs($admin)
        ->get(route('admin.ai-settings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/AiSettings/Index')
            ->where('settings.openrouter_pricing_enabled', true)
            ->where('settings.litellm_pricing_enabled', false)
            ->where('settings.xai_pricing_enabled', false)
            ->where('settings.xai_pricing_key_configured', false)
        );
});

test('admin can save the structured pricing source switches', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [
            ...baseAiSettingsPayload(),
            'openrouter_pricing_enabled' => '1',
            'litellm_pricing_enabled' => '1',
            'xai_pricing_enabled' => '0',
        ])
        ->assertRedirect(route('admin.ai-settings.index'))
        ->assertSessionHasNoErrors();

    $aiSettings = resolve(AiSettings::class);

    expect($aiSettings->openRouterPricingEnabled())->toBeTrue()
        ->and($aiSettings->liteLlmPricingEnabled())->toBeTrue()
        ->and($aiSettings->xaiPricingEnabled())->toBeFalse();
});

test('structured pricing source switches must be booleans', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.ai-settings.update'), [
            ...baseAiSettingsPayload(),
            'openrouter_pricing_enabled' => 'sometimes',
        ])
        ->assertSessionHasErrors('openrouter_pricing_enabled');
});
