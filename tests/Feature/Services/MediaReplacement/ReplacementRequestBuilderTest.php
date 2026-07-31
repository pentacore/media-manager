<?php

declare(strict_types=1);

use App\Services\MediaReplacement\ReplacementRequestBuilder;
use App\Settings\MediaReplacementSettings;

/**
 * @return array<string, mixed>
 */
function builderSnapshot(): array
{
    return [
        'ambiguous' => false,
        'service' => 'sonarr',
        'service_connection_id' => 3,
        'scope' => 'anime',
        'series_id' => 42,
        'display_name' => 'Trusted Anime S01E01',
        'episode_ids' => [101],
        'original_history_id' => 9001,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function builderCandidate(array $overrides = []): array
{
    return array_replace([
        'fingerprint' => 'fp-1',
        'title' => 'Trusted.Anime.S01E01.CR',
        'confidence' => 95,
        'matched_rules' => ['CR'],
        'season_pack' => false,
        'requires_approval' => false,
    ], $overrides);
}

test('it builds the replace_media_file payload from a snapshot and candidate', function (): void {
    $built = resolve(ReplacementRequestBuilder::class)->build(
        builderSnapshot(),
        builderCandidate(),
        ['eng'],
        'automatic',
        'Missing English subtitles.',
    );

    expect($built['payload'])->toMatchArray([
        'title' => 'Replace Trusted Anime S01E01',
        'detail' => 'Missing English subtitles.',
        'service' => 'sonarr',
        'service_connection_id' => 3,
        'scope' => 'anime',
        'candidate_fingerprint' => 'fp-1',
        'required_languages' => ['eng'],
        'confidence' => 95,
        'selection_mode' => 'automatic',
        'original_history_id' => 9001,
    ])
        ->and($built['payload']['target'])->toBe(builderSnapshot())
        ->and($built['force_requires_approval'])->toBeFalse()
        ->and($built['payload'])->not->toHaveKey('auto_check_key');
});

test('a candidate that requires approval forces approval', function (): void {
    $built = resolve(ReplacementRequestBuilder::class)->build(
        builderSnapshot(),
        builderCandidate(['requires_approval' => true]),
        ['eng'],
        'manual',
        '',
    );

    expect($built['force_requires_approval'])->toBeTrue();
});

test('a season pack forces approval under the approval_required policy', function (): void {
    resolve(MediaReplacementSettings::class)->setConfiguration([
        'season_pack_policy' => 'approval_required',
    ]);

    $built = resolve(ReplacementRequestBuilder::class)->build(
        builderSnapshot(),
        builderCandidate(['season_pack' => true]),
        ['eng'],
        'manual',
        '',
    );

    expect($built['force_requires_approval'])->toBeTrue();
});

test('a season pack does not force approval under a policy that does not require it', function (): void {
    resolve(MediaReplacementSettings::class)->setConfiguration([
        'season_pack_policy' => 'automatic_above_threshold',
    ]);

    $built = resolve(ReplacementRequestBuilder::class)->build(
        builderSnapshot(),
        builderCandidate(['season_pack' => true]),
        ['eng'],
        'manual',
        '',
    );

    expect($built['force_requires_approval'])->toBeFalse();
});

test('an auto check key is included when supplied', function (): void {
    $built = resolve(ReplacementRequestBuilder::class)->build(
        builderSnapshot(),
        builderCandidate(),
        ['eng'],
        'manual',
        '',
        'sonarr:3:42-101',
    );

    expect($built['payload']['auto_check_key'])->toBe('sonarr:3:42-101');
});

test('the rationale is truncated to a thousand characters', function (): void {
    $built = resolve(ReplacementRequestBuilder::class)->build(
        builderSnapshot(),
        builderCandidate(),
        ['eng'],
        'manual',
        str_repeat('x', 1500),
    );

    expect(mb_strlen($built['payload']['agent_rationale']))->toBe(1000);
});
