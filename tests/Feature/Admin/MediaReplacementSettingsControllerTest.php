<?php

declare(strict_types=1);

use App\Enums\MediaReplacementScope;
use App\Enums\ServiceType;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\MediaReplacement\SubtitleCheckTagSettings;
use App\Settings\MediaReplacementSettings;
use Illuminate\Support\Facades\Cache;

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
        'subtitle_check' => [
            'enabled' => false,
            'max_attempts_per_target' => 1,
            'cooldown_hours' => 24,
        ],
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
            // Non-empty so the plain-array payload variant (posted without
            // JSON encoding) survives the global ConvertEmptyStringsToNull
            // middleware, which would otherwise null out an empty string
            // before it reaches validation.
            'tv' => ['notes' => 'No special guidance.', 'rules' => []],
            'movie' => ['notes' => 'No special guidance.', 'rules' => []],
        ],
    ], $overrides);
}

/**
 * A ready-to-post payload for the media replacement update route, with the
 * configuration posted as a plain array (as opposed to the JSON-encoded
 * string the Vue form actually submits) since the request only decodes the
 * field when it arrives as a string.
 *
 * @return array<string, mixed>
 */
function mediaReplacementValidPayload(): array
{
    return ['media_replacement' => validMediaReplacementConfiguration()];
}

test('guests cannot access media replacement settings', function (): void {
    $this->get(route('admin.media-replacement.index'))
        ->assertRedirect(route('login'));
});

test('members cannot open media replacement settings', function (): void {
    $this->actingAs(User::factory()->member()->create())
        ->get(route('admin.media-replacement.index'))
        ->assertForbidden();
});

test('media replacement settings page is reachable with AI disabled', function (): void {
    config()->set('mediamanager.ai.enabled', false);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.media-replacement.index'))
        ->assertOk();
});

test('index exposes media replacement configuration and enum options', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.media-replacement.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/MediaReplacement/Index')
            ->where('settings.media_replacement.automatic_selection_threshold', 90)
            ->where('settings.media_replacement.season_pack_policy', 'approval_required')
            ->where('settings.media_replacement.global_languages', ['eng'])
            ->where('settings.media_replacement.subtitle_check', [
                'enabled' => false,
                'max_attempts_per_target' => 1,
                'cooldown_hours' => 24,
            ])
            ->has('seasonPackPolicies')
            ->has('subtitleRuleStrengths')
            ->has('conditionFields')
        );
});

test('index does not expose legacy Sonarr root-folder classifications', function (): void {
    $admin = User::factory()->admin()->create();
    resolve(MediaReplacementSettings::class)->setConfiguration([
        'sonarr_root_folders' => [[
            'service_connection_id' => 123,
            'root_folder_id' => 2,
            'path' => '/anime',
            'scope' => 'anime',
        ]],
    ]);

    $this->actingAs($admin)
        ->get(route('admin.media-replacement.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->missing('sonarrRootFolders')
            ->missing('settings.media_replacement.sonarr_root_folders')
        );
});

test('update writes settings through the existing storage key', function (): void {
    $payload = mediaReplacementValidPayload();
    $payload['media_replacement']['automatic_selection_threshold'] = 77;

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.media-replacement.update'), $payload)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(resolve(MediaReplacementSettings::class)->automaticSelectionThreshold())->toBe(77);
});

test('admin can update media replacement settings and the service round-trips them', function (): void {
    $admin = User::factory()->admin()->create();
    $configuration = validMediaReplacementConfiguration();

    $this->actingAs($admin)
        ->put(route('admin.media-replacement.update'), [
            'media_replacement' => json_encode($configuration, JSON_THROW_ON_ERROR),
        ])
        ->assertRedirect(route('admin.media-replacement.index'))
        ->assertSessionHasNoErrors();

    $mediaReplacementSettings = resolve(MediaReplacementSettings::class);

    expect($mediaReplacementSettings->automaticSelectionThreshold())->toBe(95)
        ->and($mediaReplacementSettings->automaticSelectionEnabled())->toBeTrue()
        ->and($mediaReplacementSettings->effectiveLanguages(MediaReplacementScope::Anime))->toBe(['jpn'])
        ->and($mediaReplacementSettings->effectiveLanguages(MediaReplacementScope::Tv))->toBe(['eng'])
        ->and($mediaReplacementSettings->guidance(MediaReplacementScope::Anime)['notes'])->toBe('CR-tagged releases are trusted.')
        ->and($mediaReplacementSettings->guidance(MediaReplacementScope::Anime)['rules'])->toHaveCount(1);
});

test('updating media replacement settings preserves legacy Sonarr root-folder classifications', function (): void {
    $admin = User::factory()->admin()->create();
    $legacyAssignments = [[
        'service_connection_id' => 123,
        'root_folder_id' => 2,
        'path' => '/anime',
        'scope' => 'anime',
    ]];
    resolve(MediaReplacementSettings::class)->setConfiguration([
        'sonarr_root_folders' => $legacyAssignments,
    ]);

    $this->actingAs($admin)
        ->put(route('admin.media-replacement.update'), [
            'media_replacement' => json_encode(validMediaReplacementConfiguration(), JSON_THROW_ON_ERROR),
        ])
        ->assertRedirect(route('admin.media-replacement.index'))
        ->assertSessionHasNoErrors();

    expect(resolve(MediaReplacementSettings::class)->sonarrRootFolders())->toBe($legacyAssignments);
});

test('update accepts a request without media replacement and preserves stored configuration', function (): void {
    $admin = User::factory()->admin()->create();
    resolve(MediaReplacementSettings::class)->setConfiguration(validMediaReplacementConfiguration());

    $this->actingAs($admin)
        ->put(route('admin.media-replacement.update'), [])
        ->assertRedirect(route('admin.media-replacement.index'))
        ->assertSessionHasNoErrors();

    expect(resolve(MediaReplacementSettings::class)->automaticSelectionThreshold())->toBe(95);
});

test('update rejects invalid media replacement configuration', function (array $overrides, string $errorKey): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.media-replacement.update'), [
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
    'missing subtitle check block' => [
        ['subtitle_check' => null],
        'media_replacement.subtitle_check',
    ],
    'unknown subtitle check key' => [
        ['subtitle_check' => [
            'enabled' => false,
            'max_attempts_per_target' => 1,
            'cooldown_hours' => 24,
            'tags' => ['subtitle-check'],
        ]],
        'media_replacement.subtitle_check',
    ],
    'non-boolean subtitle check toggle' => [
        ['subtitle_check' => [
            'enabled' => 'nope',
            'max_attempts_per_target' => 1,
            'cooldown_hours' => 24,
        ]],
        'media_replacement.subtitle_check.enabled',
    ],
    'subtitle check attempts below one' => [
        ['subtitle_check' => [
            'enabled' => true,
            'max_attempts_per_target' => 0,
            'cooldown_hours' => 24,
        ]],
        'media_replacement.subtitle_check.max_attempts_per_target',
    ],
    'subtitle check attempts above ten' => [
        ['subtitle_check' => [
            'enabled' => true,
            'max_attempts_per_target' => 11,
            'cooldown_hours' => 24,
        ]],
        'media_replacement.subtitle_check.max_attempts_per_target',
    ],
    'subtitle check cooldown below one hour' => [
        ['subtitle_check' => [
            'enabled' => true,
            'max_attempts_per_target' => 1,
            'cooldown_hours' => 0,
        ]],
        'media_replacement.subtitle_check.cooldown_hours',
    ],
    'subtitle check cooldown above 720 hours' => [
        ['subtitle_check' => [
            'enabled' => true,
            'max_attempts_per_target' => 1,
            'cooldown_hours' => 721,
        ]],
        'media_replacement.subtitle_check.cooldown_hours',
    ],
]);

test('the subtitle check settings round-trip through the media replacement form', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.media-replacement.update'), [
            'media_replacement' => json_encode(validMediaReplacementConfiguration([
                'subtitle_check' => [
                    'enabled' => true,
                    'max_attempts_per_target' => 2,
                    'cooldown_hours' => 12,
                ],
            ]), JSON_THROW_ON_ERROR),
        ])
        ->assertRedirect(route('admin.media-replacement.index'))
        ->assertSessionHasNoErrors();

    $mediaReplacementSettings = resolve(MediaReplacementSettings::class);

    expect($mediaReplacementSettings->subtitleCheckEnabled())->toBeTrue()
        ->and($mediaReplacementSettings->subtitleCheckMaxAttempts())->toBe(2)
        ->and($mediaReplacementSettings->subtitleCheckCooldownHours())->toBe(12);
});

test('saving the media replacement form leaves per-connection subtitle-check tags alone', function (): void {
    $admin = User::factory()->admin()->create();
    $subtitleCheckTagSettings = resolve(SubtitleCheckTagSettings::class);
    $serviceConnection = ServiceConnection::factory()->create([
        'type' => ServiceType::Sonarr,
        'settings' => $subtitleCheckTagSettings->mergeInto([], ['subtitle-check']),
    ]);

    $this->actingAs($admin)
        ->put(route('admin.media-replacement.update'), [
            'media_replacement' => json_encode(validMediaReplacementConfiguration([
                'subtitle_check' => [
                    'enabled' => true,
                    'max_attempts_per_target' => 2,
                    'cooldown_hours' => 12,
                ],
            ]), JSON_THROW_ON_ERROR),
        ])
        ->assertRedirect(route('admin.media-replacement.index'))
        ->assertSessionHasNoErrors();

    expect($subtitleCheckTagSettings->forConnection($serviceConnection->refresh()))->toBe(['subtitle-check']);
});

test('update rejects malformed media replacement json', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.media-replacement.update'), [
            'media_replacement' => '{not valid json',
        ])
        ->assertSessionHasErrors('media_replacement.automatic_selection_enabled');
});

test('index exposes the open attention count for the attempts tab badge', function (): void {
    MediaReplacementAttempt::factory()->needsAttention()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.media-replacement.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('attentionCount', 1));
});
