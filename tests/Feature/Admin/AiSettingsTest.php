<?php

declare(strict_types=1);

use App\Enums\AiMode;
use App\Enums\MediaReplacementScope;
use App\Models\User;
use App\Settings\AiSettings;
use App\Settings\MediaReplacementSettings;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Enums\Lab;

beforeEach(function (): void {
    Cache::flush();
});

/**
 * A complete, valid media replacement configuration for update requests.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validMediaReplacementConfiguration(array $overrides = []): array
{
    return array_replace([
        'automatic_selection_enabled' => true,
        'automatic_selection_threshold' => 95,
        'global_languages' => ['English'],
        'scoped_languages' => ['anime' => ['Japanese'], 'tv' => null, 'movie' => null],
        'season_pack_policy' => 'approval_required',
        'guidance' => [
            'anime' => [
                'notes' => 'CR-tagged releases are trusted.',
                'rules' => [[
                    'name' => 'Crunchyroll English',
                    'enabled' => true,
                    'strength' => 'guarantee',
                    'languages' => ['English'],
                    'conditions' => [['field' => 'title', 'value' => 'CR']],
                    'explanation' => 'The CR tag identifies Crunchyroll releases.',
                ]],
            ],
            'tv' => ['notes' => '', 'rules' => []],
            'movie' => ['notes' => '', 'rules' => []],
        ],
    ], $overrides);
}

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

test('index exposes media replacement configuration and enum options', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.ai-settings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/AiSettings/Index')
            ->where('settings.media_replacement.automatic_selection_threshold', 90)
            ->where('settings.media_replacement.season_pack_policy', 'approval_required')
            ->where('settings.media_replacement.global_languages', ['eng'])
            ->has('seasonPackPolicies')
            ->has('subtitleRuleStrengths')
            ->has('conditionFields')
        );
});

test('admin can update media replacement settings and the service round-trips them', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [
            ...baseAiSettingsPayload(),
            'media_replacement' => json_encode(validMediaReplacementConfiguration(), JSON_THROW_ON_ERROR),
        ])
        ->assertRedirect(route('admin.ai-settings.index'))
        ->assertSessionHasNoErrors();

    $settings = resolve(MediaReplacementSettings::class);

    expect($settings->automaticSelectionThreshold())->toBe(95)
        ->and($settings->automaticSelectionEnabled())->toBeTrue()
        ->and($settings->effectiveLanguages(MediaReplacementScope::Anime))->toBe(['jpn'])
        ->and($settings->effectiveLanguages(MediaReplacementScope::Tv))->toBe(['eng'])
        ->and($settings->guidance(MediaReplacementScope::Anime)['notes'])->toBe('CR-tagged releases are trusted.')
        ->and($settings->guidance(MediaReplacementScope::Anime)['rules'])->toHaveCount(1);
});

test('update accepts a request without media replacement and preserves stored configuration', function (): void {
    $admin = User::factory()->admin()->create();
    resolve(MediaReplacementSettings::class)->setConfiguration(validMediaReplacementConfiguration());

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), baseAiSettingsPayload())
        ->assertRedirect(route('admin.ai-settings.index'))
        ->assertSessionHasNoErrors();

    expect(resolve(MediaReplacementSettings::class)->automaticSelectionThreshold())->toBe(95);
});

test('update rejects invalid media replacement configuration', function (array $overrides, string $errorKey): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [
            ...baseAiSettingsPayload(),
            'media_replacement' => json_encode(
                validMediaReplacementConfiguration($overrides),
                JSON_THROW_ON_ERROR,
            ),
        ])
        ->assertSessionHasErrors($errorKey);
})->with([
    'threshold above 100' => [
        ['automatic_selection_threshold' => 101],
        'media_replacement.automatic_selection_threshold',
    ],
    'empty global languages' => [
        ['global_languages' => []],
        'media_replacement.global_languages',
    ],
    'unknown scope key' => [
        ['scoped_languages' => ['anime' => null, 'tv' => null, 'movie' => null, 'music' => ['English']]],
        'media_replacement.scoped_languages',
    ],
    'notes longer than 4000 characters' => [
        ['guidance' => [
            'anime' => ['notes' => str_repeat('a', 4001), 'rules' => []],
            'tv' => ['notes' => '', 'rules' => []],
            'movie' => ['notes' => '', 'rules' => []],
        ]],
        'media_replacement.guidance.anime.notes',
    ],
    'unknown condition field' => [
        ['guidance' => [
            'anime' => [
                'notes' => '',
                'rules' => [[
                    'name' => 'Bad condition',
                    'enabled' => true,
                    'strength' => 'guarantee',
                    'languages' => ['English'],
                    'conditions' => [['field' => 'indexer', 'value' => 'CR']],
                ]],
            ],
            'tv' => ['notes' => '', 'rules' => []],
            'movie' => ['notes' => '', 'rules' => []],
        ]],
        'media_replacement.guidance.anime.rules.0.conditions.0.field',
    ],
    'unknown strength' => [
        ['guidance' => [
            'anime' => [
                'notes' => '',
                'rules' => [[
                    'name' => 'Bad strength',
                    'enabled' => true,
                    'strength' => 'absolute',
                    'languages' => ['English'],
                    'conditions' => [['field' => 'title', 'value' => 'CR']],
                ]],
            ],
            'tv' => ['notes' => '', 'rules' => []],
            'movie' => ['notes' => '', 'rules' => []],
        ]],
        'media_replacement.guidance.anime.rules.0.strength',
    ],
    'rule without languages' => [
        ['guidance' => [
            'anime' => [
                'notes' => '',
                'rules' => [[
                    'name' => 'No languages',
                    'enabled' => true,
                    'strength' => 'guarantee',
                    'languages' => [],
                    'conditions' => [['field' => 'title', 'value' => 'CR']],
                ]],
            ],
            'tv' => ['notes' => '', 'rules' => []],
            'movie' => ['notes' => '', 'rules' => []],
        ]],
        'media_replacement.guidance.anime.rules.0.languages',
    ],
]);

test('update rejects malformed media replacement json', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.ai-settings.update'), [
            ...baseAiSettingsPayload(),
            'media_replacement' => '{not valid json',
        ])
        ->assertSessionHasErrors('media_replacement.automatic_selection_enabled');
});
