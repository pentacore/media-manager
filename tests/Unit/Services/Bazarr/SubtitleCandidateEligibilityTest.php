<?php

declare(strict_types=1);

use App\Services\Bazarr\SubtitleCandidateEligibility;
use App\Services\MediaReplacement\LanguageNormalizer;

beforeEach(function (): void {
    $this->eligibility = new SubtitleCandidateEligibility(new LanguageNormalizer);
    $this->candidate = [
        'fingerprint' => hash('sha256', 'candidate'),
        'provider' => 'OpenSubtitles',
        'language' => 'eng',
        'forced' => false,
        'hearing_impaired' => false,
        'score' => 95.0,
    ];
    $this->requirement = [
        'code' => 'eng',
        'forced' => false,
        'hearing_impaired' => false,
    ];
    $this->context = [
        'minimum_score' => 90,
        'available_providers' => ['OpenSubtitles'],
        'threshold_available' => true,
    ];
});

test('an eligible candidate matches language qualifiers provider and threshold', function (): void {
    expect($this->eligibility->classify($this->candidate, $this->requirement, $this->context))
        ->toBe('eligible');
});

test('candidate rejections are classified without retaining candidate payloads', function (array $candidate, array $requirement, array $context, string $expected): void {
    expect($this->eligibility->classify(
        [...$this->candidate, ...$candidate],
        [...$this->requirement, ...$requirement],
        [...$this->context, ...$context],
    ))->toBe($expected);
})->with([
    'wrong language' => [['language' => 'swe'], [], [], 'wrong_language'],
    'wrong forced qualifier' => [['forced' => true], [], [], 'wrong_qualifier'],
    'wrong hearing impaired qualifier' => [['hearing_impaired' => true], [], [], 'wrong_qualifier'],
    'provider unavailable' => [['provider' => 'DisabledProvider'], [], [], 'provider_unavailable'],
    'below threshold' => [['score' => 89.9], [], [], 'below_threshold'],
    'missing fingerprint' => [['fingerprint' => null], [], [], 'malformed'],
    'missing score' => [['score' => null], [], [], 'malformed'],
    'invalid requirement' => [[], ['code' => null], [], 'malformed'],
    'threshold unavailable' => [[], [], ['threshold_available' => false], 'capability_limited'],
]);

test('threshold capability limits classification even when there are no candidates to accept', function (): void {
    expect($this->eligibility->classify($this->candidate, $this->requirement, [
        ...$this->context,
        'threshold_available' => false,
    ]))->toBe('capability_limited');
});
