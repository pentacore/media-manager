<?php

declare(strict_types=1);

use App\Enums\AiMode;
use App\Models\User;
use App\Settings\AiSettings;
use App\Settings\MediaReplacementSettings;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Enums\Lab;

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
        'model' => 'gpt-5-mini',
        'title_model' => 'gpt-5.4-nano',
        'advisor_reasoning_level' => 'none',
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
    resolve(AiSettings::class)->setModel('claude-haiku-4-5');

    $this->actingAs($admin)
        ->get(route('admin.ai-settings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/AiSettings/Index')
            ->where('settings.model', 'claude-haiku-4-5')
            ->has('modes')
        );
});

test('index shows the raw auto sentinel while the accessor resolves a concrete model', function (): void {
    $admin = User::factory()->admin()->create();
    resolve(AiSettings::class)->setTitleModel(AiSettings::AUTO_MODEL);

    $this->actingAs($admin)
        ->get(route('admin.ai-settings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/AiSettings/Index')
            ->where('settings.title_model', AiSettings::AUTO_MODEL)
        );

    $aiSettings = resolve(AiSettings::class);
    expect($aiSettings->rawTitleModel())->toBe(AiSettings::AUTO_MODEL);
    expect($aiSettings->titleModel())->not->toBe(AiSettings::AUTO_MODEL);
});

test('admin can update settings', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [
            'mode' => 'advisory',
            'model' => 'gemini-3-flash-preview',
            'title_model' => 'gpt-5.4-nano',
            'advisor_reasoning_level' => 'medium',
        ])
        ->assertRedirect(route('admin.ai-settings.index'));

    $aiSettings = resolve(AiSettings::class);
    expect($aiSettings->mode())->toBe(AiMode::Advisory);
    expect($aiSettings->model())->toBe('gemini-3-flash-preview');
    expect($aiSettings->titleModel())->toBe('gpt-5.4-nano');
    expect($aiSettings->advisorReasoningLevel())->toBe('medium');
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

test('admin can set and clear the failover provider', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [
            'mode' => 'executive',
            'model' => 'gpt-5-mini',
            'title_model' => 'gpt-5.4-nano',
            'advisor_reasoning_level' => 'none',
            'failover_provider' => 'anthropic',
        ])
        ->assertRedirect(route('admin.ai-settings.index'));

    expect(resolve(AiSettings::class)->failoverProvider())->toBe(Lab::Anthropic);

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [
            'mode' => 'executive',
            'model' => 'gpt-5-mini',
            'title_model' => 'gpt-5.4-nano',
            'advisor_reasoning_level' => 'none',
            'failover_provider' => 'none',
        ])
        ->assertRedirect(route('admin.ai-settings.index'));

    expect(resolve(AiSettings::class)->failoverProvider())->toBeNull();
});

test('update rejects an unknown failover provider', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [
            'mode' => 'executive',
            'model' => 'gpt-5-mini',
            'title_model' => 'gpt-5.4-nano',
            'advisor_reasoning_level' => 'none',
            'failover_provider' => 'cohere',
        ])
        ->assertSessionHasErrors('failover_provider');
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
            ->has('ignorablePricingProviders')
            ->where('ignorablePricingProviders.0.value', 'openai')
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
            'model' => 'gpt-5-mini',
            'title_model' => 'gpt-5.4-nano',
        ])
        ->assertSessionHasErrors('mode');
});

test('update requires mode, model, and title_model', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [])
        ->assertSessionHasErrors(['mode', 'model', 'title_model', 'advisor_reasoning_level']);
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
