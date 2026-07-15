<?php

declare(strict_types=1);

use App\Enums\MediaReplacementScope;
use App\Enums\MediaReplacementStatus;
use App\Enums\SeasonPackPolicy;
use App\Enums\SubtitleRuleStrength;
use App\Models\AppSetting;
use App\Settings\AppSettings;
use App\Settings\MediaReplacementSettings;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
});

test('media replacement enums expose stable domain values', function (): void {
    expect(MediaReplacementScope::values())->toBe(['anime', 'tv', 'movie'])
        ->and(MediaReplacementStatus::values())->toBe([
            'requested',
            'downloading',
            'imported',
            'verified',
            'failed',
            'needs_attention',
        ])
        ->and(SeasonPackPolicy::values())->toBe([
            'never',
            'approval_required',
            'automatic_above_threshold',
        ])
        ->and(SubtitleRuleStrength::values())->toBe([
            'guarantee',
            'strong_evidence',
            'preference',
        ])
        ->and(SubtitleRuleStrength::Guarantee->confidence())->toBe(98)
        ->and(SubtitleRuleStrength::StrongEvidence->confidence())->toBe(85)
        ->and(SubtitleRuleStrength::Preference->confidence())->toBeNull();
});

test('media replacement enum options have human-readable labels', function (): void {
    expect(SeasonPackPolicy::mapForSelect(labelKey: 'label'))->toBe([
        ['label' => 'Approval required', 'value' => 'approval_required'],
        ['label' => 'Automatic above threshold', 'value' => 'automatic_above_threshold'],
        ['label' => 'Never', 'value' => 'never'],
    ])->and(SubtitleRuleStrength::mapForSelect(labelKey: 'label'))->toBe([
        ['label' => 'Guarantee', 'value' => 'guarantee'],
        ['label' => 'Preference', 'value' => 'preference'],
        ['label' => 'Strong evidence', 'value' => 'strong_evidence'],
    ]);
});

test('it provides safe defaults', function (): void {
    $mediaReplacementSettings = resolve(MediaReplacementSettings::class);

    expect($mediaReplacementSettings->automaticSelectionEnabled())->toBeFalse()
        ->and($mediaReplacementSettings->automaticSelectionThreshold())->toBe(90)
        ->and($mediaReplacementSettings->seasonPackPolicy())->toBe(SeasonPackPolicy::ApprovalRequired)
        ->and($mediaReplacementSettings->effectiveLanguages(MediaReplacementScope::Anime))->toBe(['eng'])
        ->and($mediaReplacementSettings->guidance(MediaReplacementScope::Anime))->toBe([
            'rules' => [],
            'notes' => '',
        ]);
});

test('scoped languages inherit globally and request overrides win', function (): void {
    $mediaReplacementSettings = resolve(MediaReplacementSettings::class);
    $mediaReplacementSettings->setConfiguration([
        'global_languages' => ['eng', 'swe'],
        'scoped_languages' => [
            'anime' => ['jpn'],
            'movie' => [],
        ],
    ]);

    expect($mediaReplacementSettings->effectiveLanguages(MediaReplacementScope::Tv))->toBe(['eng', 'swe'])
        ->and($mediaReplacementSettings->effectiveLanguages(MediaReplacementScope::Anime))->toBe(['jpn'])
        ->and($mediaReplacementSettings->effectiveLanguages(MediaReplacementScope::Movie))->toBe([])
        ->and($mediaReplacementSettings->effectiveLanguages(MediaReplacementScope::Anime, ['English']))->toBe(['eng'])
        ->and($mediaReplacementSettings->effectiveLanguages(MediaReplacementScope::Anime, []))->toBe([]);
});

test('it persists one normalized configuration object atomically', function (): void {
    $mediaReplacementSettings = resolve(MediaReplacementSettings::class);
    $mediaReplacementSettings->setConfiguration([
        'automatic_selection_enabled' => true,
        'global_languages' => [' English ', 'SV', '', 'english'],
        'scoped_languages' => [
            'movie' => ['Japanese', 'ja'],
        ],
    ]);

    $configuration = $mediaReplacementSettings->configuration();
    $persistedSetting = AppSetting::find('ai.media_replacement');

    expect($persistedSetting)->not->toBeNull()
        ->and($persistedSetting?->value)->toBe($configuration)
        ->and($configuration['automatic_selection_enabled'])->toBeTrue()
        ->and($configuration['automatic_selection_threshold'])->toBe(90)
        ->and($configuration['global_languages'])->toBe(['eng', 'swe'])
        ->and($configuration['scoped_languages'])->toBe([
            'anime' => null,
            'tv' => null,
            'movie' => ['jpn'],
        ])
        ->and(AppSetting::query()->where('key', 'like', 'ai.media_replacement%')->count())->toBe(1);
});

test('it recursively merges missing stored keys with immutable defaults', function (): void {
    resolve(AppSettings::class)->set('ai.media_replacement', [
        'guidance' => [
            'anime' => [
                'notes' => 'Prefer complete releases.',
            ],
        ],
    ]);

    $configuration = resolve(MediaReplacementSettings::class)->configuration();

    expect($configuration['global_languages'])->toBe(['eng'])
        ->and($configuration['guidance']['anime'])->toBe([
            'rules' => [],
            'notes' => 'Prefer complete releases.',
        ])
        ->and($configuration['guidance']['tv'])->toBe([
            'rules' => [],
            'notes' => '',
        ]);
});

test('it safely restores malformed nested containers to scoped defaults', function (): void {
    resolve(AppSettings::class)->set('ai.media_replacement', [
        'scoped_languages' => 'invalid',
        'guidance' => 'invalid',
    ]);

    $mediaReplacementSettings = resolve(MediaReplacementSettings::class);

    expect($mediaReplacementSettings->effectiveLanguages(MediaReplacementScope::Anime))->toBe(['eng'])
        ->and($mediaReplacementSettings->guidance(MediaReplacementScope::Anime))->toBe([
            'rules' => [],
            'notes' => '',
        ]);
});

test('it restores invalid scalar settings to safe canonical defaults', function (array $invalidConfiguration): void {
    resolve(AppSettings::class)->set('ai.media_replacement', $invalidConfiguration);

    $configuration = resolve(MediaReplacementSettings::class)->configuration();

    expect($configuration['automatic_selection_enabled'])->toBeFalse()
        ->and($configuration['automatic_selection_threshold'])->toBe(90)
        ->and($configuration['season_pack_policy'])->toBe('approval_required');
})->with([
    'string boolean' => [['automatic_selection_enabled' => 'false']],
    'integer boolean' => [['automatic_selection_enabled' => 1]],
    'numeric string threshold' => [['automatic_selection_threshold' => '50']],
    'array threshold' => [['automatic_selection_threshold' => [50]]],
    'threshold below range' => [['automatic_selection_threshold' => -1]],
    'threshold above range' => [['automatic_selection_threshold' => 101]],
    'unknown season pack policy' => [['season_pack_policy' => 'sometimes']],
    'array season pack policy' => [['season_pack_policy' => ['approval_required']]],
]);

test('it preserves valid boundary scalar settings', function (): void {
    $mediaReplacementSettings = resolve(MediaReplacementSettings::class);

    $mediaReplacementSettings->setConfiguration([
        'automatic_selection_enabled' => true,
        'automatic_selection_threshold' => 0,
        'season_pack_policy' => 'automatic_above_threshold',
    ]);

    expect($mediaReplacementSettings->automaticSelectionEnabled())->toBeTrue()
        ->and($mediaReplacementSettings->automaticSelectionThreshold())->toBe(0)
        ->and($mediaReplacementSettings->seasonPackPolicy())->toBe(SeasonPackPolicy::AutomaticAboveThreshold);

    $mediaReplacementSettings->setConfiguration(['automatic_selection_threshold' => 100]);

    expect($mediaReplacementSettings->automaticSelectionThreshold())->toBe(100);
});

test('it emits only the canonical known configuration schema', function (): void {
    resolve(AppSettings::class)->set('ai.media_replacement', [
        'global_languages' => 'English',
        'unexpected_key' => 'discard me',
        'scoped_languages' => [
            'documentary' => ['eng'],
        ],
        'guidance' => [
            'anime' => [
                'rules' => 'invalid',
                'notes' => ['invalid'],
                'unexpected' => 'discard me',
            ],
            'tv' => [
                'rules' => [['strength' => 'preference'], 'opaque'],
                'notes' => 'Keep this.',
            ],
            'movie' => 'invalid',
            'documentary' => ['rules' => [], 'notes' => 'discard me'],
        ],
    ]);

    expect(resolve(MediaReplacementSettings::class)->configuration())->toBe([
        'automatic_selection_enabled' => false,
        'automatic_selection_threshold' => 90,
        'global_languages' => ['eng'],
        'scoped_languages' => [
            'anime' => null,
            'tv' => null,
            'movie' => null,
        ],
        'season_pack_policy' => 'approval_required',
        'guidance' => [
            'anime' => [
                'rules' => [],
                'notes' => '',
            ],
            'tv' => [
                'rules' => [['strength' => 'preference'], 'opaque'],
                'notes' => 'Keep this.',
            ],
            'movie' => [
                'rules' => [],
                'notes' => '',
            ],
        ],
    ]);
});

test('it safely filters mixed configured and request language lists', function (): void {
    $mediaReplacementSettings = resolve(MediaReplacementSettings::class);
    $mediaReplacementSettings->setConfiguration([
        'global_languages' => ['English', 42, ['Swedish'], null, 'sv'],
        'scoped_languages' => [
            'anime' => ['Japanese', 123, ['English'], null, 'EN'],
        ],
    ]);

    expect($mediaReplacementSettings->effectiveLanguages(MediaReplacementScope::Tv))->toBe(['eng', 'swe'])
        ->and($mediaReplacementSettings->effectiveLanguages(MediaReplacementScope::Anime))->toBe(['jpn', 'eng'])
        ->and($mediaReplacementSettings->effectiveLanguages(MediaReplacementScope::Anime, [
            'Swedish',
            false,
            ['English'],
            null,
            'FI',
        ]))->toBe(['swe', 'fin']);
});
