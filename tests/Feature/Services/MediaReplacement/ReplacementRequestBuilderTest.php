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

test('the payload keys stay in the order the executor and the approval card were built against', function (): void {
    // The tool emitted this exact shape before the builder was extracted, and a
    // second entry point now emits it too. Key set and order are the contract:
    // toMatchArray is order-insensitive and subset-only, so only this pins them.
    $built = resolve(ReplacementRequestBuilder::class)->build(
        builderSnapshot(),
        builderCandidate(),
        ['eng'],
        'automatic',
        'Missing English subtitles.',
    );

    expect(array_keys($built['payload']))->toBe([
        'title',
        'detail',
        'service',
        'service_connection_id',
        'scope',
        'target',
        'candidate_fingerprint',
        'candidate',
        'required_languages',
        'confidence',
        'matched_rules',
        'selection_mode',
        'agent_rationale',
        'original_history_id',
        'verify_subtitles',
    ]);
});

test('builder defaults verify_subtitles to true and honours an explicit false', function (): void {
    $built = resolve(ReplacementRequestBuilder::class)->build(
        builderSnapshot(),
        builderCandidate(),
        ['eng'],
        'manual',
        'why',
    );
    $builtDisabled = resolve(ReplacementRequestBuilder::class)->build(
        builderSnapshot(),
        builderCandidate(),
        ['eng'],
        'manual',
        'why',
        verifySubtitles: false,
    );

    expect($built['payload']['verify_subtitles'])->toBeTrue()
        ->and($builtDisabled['payload']['verify_subtitles'])->toBeFalse();
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
        autoCheckKey: 'sonarr:3:42-101',
    );

    expect($built['payload']['auto_check_key'])->toBe('sonarr:3:42-101')
        ->and(array_keys($built['payload']))->toBe([
            'title',
            'detail',
            'service',
            'service_connection_id',
            'scope',
            'target',
            'candidate_fingerprint',
            'candidate',
            'required_languages',
            'confidence',
            'matched_rules',
            'selection_mode',
            'agent_rationale',
            'original_history_id',
            'verify_subtitles',
            'auto_check_key',
        ]);
});

test('a subtitle case id is included when supplied, after the base keys', function (): void {
    $built = resolve(ReplacementRequestBuilder::class)->build(
        builderSnapshot(),
        builderCandidate(),
        ['eng'],
        'automatic',
        'Advisor requested a replacement.',
        subtitleCaseId: 77,
    );

    expect($built['payload']['subtitle_case_id'])->toBe(77)
        ->and($built['payload'])->not->toHaveKey('auto_check_key')
        ->and(array_keys($built['payload']))->toBe([
            'title',
            'detail',
            'service',
            'service_connection_id',
            'scope',
            'target',
            'candidate_fingerprint',
            'candidate',
            'required_languages',
            'confidence',
            'matched_rules',
            'selection_mode',
            'agent_rationale',
            'original_history_id',
            'verify_subtitles',
            'subtitle_case_id',
        ]);
});

test('the two correlation keys belong to different callers and are never both emitted', function (): void {
    // auto_check_key is the automatic check's; subtitle_case_id is the Bazarr
    // advisor's. Nothing supplies both, and each caller's request must carry only
    // its own — a request answering to both correlation schemes would be counted
    // by the attempt cap AND owned by an advisor case.
    $autoCheck = resolve(ReplacementRequestBuilder::class)->build(
        builderSnapshot(), builderCandidate(), ['eng'], 'manual', '', autoCheckKey: 'sonarr:3:42-101',
    );
    $advisor = resolve(ReplacementRequestBuilder::class)->build(
        builderSnapshot(), builderCandidate(), ['eng'], 'automatic', '', subtitleCaseId: 77,
    );
    $neither = resolve(ReplacementRequestBuilder::class)->build(
        builderSnapshot(), builderCandidate(), ['eng'], 'manual', '',
    );

    expect($autoCheck['payload'])->not->toHaveKey('subtitle_case_id')
        ->and($advisor['payload'])->not->toHaveKey('auto_check_key')
        ->and($neither['payload'])->not->toHaveKey('auto_check_key')
        ->and($neither['payload'])->not->toHaveKey('subtitle_case_id');
});

test('the title is bounded so a long display name cannot overflow its column', function (): void {
    $snapshot = builderSnapshot();
    $snapshot['display_name'] = str_repeat('n', 400);

    $built = resolve(ReplacementRequestBuilder::class)->build(
        $snapshot,
        builderCandidate(),
        ['eng'],
        'manual',
        '',
    );

    expect(mb_strlen($built['payload']['title']))->toBe(300);
});

test('the detail is bounded by the same thousand characters as the rationale', function (): void {
    $built = resolve(ReplacementRequestBuilder::class)->build(
        builderSnapshot(),
        builderCandidate(),
        ['eng'],
        'manual',
        str_repeat('x', 1500),
    );

    expect(mb_strlen($built['payload']['detail']))->toBe(1000)
        ->and($built['payload']['detail'])->toBe($built['payload']['agent_rationale']);
});

test('matched rules default to an empty list rather than null', function (): void {
    $candidate = builderCandidate();
    unset($candidate['matched_rules']);

    $built = resolve(ReplacementRequestBuilder::class)->build(
        builderSnapshot(),
        $candidate,
        ['eng'],
        'manual',
        '',
    );

    expect($built['payload']['matched_rules'])->toBe([]);
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
