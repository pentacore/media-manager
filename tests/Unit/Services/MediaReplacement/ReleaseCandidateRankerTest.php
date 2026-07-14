<?php

declare(strict_types=1);

use App\Enums\SeasonPackPolicy;
use App\Enums\SubtitleRuleStrength;
use App\Services\MediaReplacement\LanguageNormalizer;
use App\Services\MediaReplacement\ReleaseCandidateRanker;
use App\Services\MediaReplacement\ReleaseFingerprint;
use App\Services\MediaReplacement\ReleaseRuleMatcher;

/**
 * @param  list<mixed>  $languages
 * @return array<string, mixed>
 */
function rcrRule(
    string $name = 'Trusted CR',
    SubtitleRuleStrength $subtitleRuleStrength = SubtitleRuleStrength::Guarantee,
    array $languages = ['eng'],
    string $titleNeedle = 'CR',
    ?string $explanation = 'Trusted subtitle release.',
): array {
    $rule = [
        'name' => $name,
        'enabled' => true,
        'strength' => $subtitleRuleStrength->value,
        'languages' => $languages,
        'conditions' => [
            ['field' => 'title', 'value' => $titleNeedle],
        ],
    ];

    if ($explanation !== null) {
        $rule['explanation'] = $explanation;
    }

    return $rule;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rcrRelease(array $overrides = []): array
{
    return array_replace([
        'guid' => 'guid-1',
        'indexerId' => 10,
        'title' => 'Example.Show.S01E01.CR.1080p',
        'releaseGroup' => 'SubsPlease',
        'subGroup' => 'CR',
        'episodeIds' => [101],
        'downloadAllowed' => true,
        'rejections' => [],
        'fullSeason' => false,
        'customFormats' => [['name' => 'Dual Audio']],
        'customFormatScore' => 10,
        'qualityWeight' => 100,
        'quality' => ['quality' => ['name' => 'Bluray-1080p']],
        'size' => 1_500_000_000,
        'seeders' => 5,
        'ageMinutes' => 60,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rcrSonarrTarget(array $overrides = []): array
{
    return array_replace([
        'service' => 'sonarr',
        'episode_ids' => [101],
        'installed_release' => 'installed CR',
    ], $overrides);
}

function rcrRanker(): ReleaseCandidateRanker
{
    return new ReleaseCandidateRanker(
        new ReleaseRuleMatcher,
        new ReleaseFingerprint,
        new LanguageNormalizer,
    );
}

/** @return array<string, int> */
function rcrEmptyExcluded(): array
{
    return [
        'mapping' => 0,
        'unavailable' => 0,
        'rejected' => 0,
        'installed_release' => 0,
        'subtitle_evidence' => 0,
        'season_pack_policy' => 0,
    ];
}

test('confidence ranks ahead of arr suitability scores', function (): void {
    $result = rcrRanker()->rank(
        [
            rcrRelease([
                'guid' => 'guarantee',
                'title' => 'Example Show CR',
                'customFormatScore' => 0,
                'seeders' => 1,
            ]),
            rcrRelease([
                'guid' => 'strong',
                'title' => 'Example Show WEB',
                'customFormatScore' => 500,
                'seeders' => 50,
            ]),
        ],
        ['eng'],
        [
            rcrRule('Guaranteed CR', SubtitleRuleStrength::Guarantee, ['eng'], 'CR'),
            rcrRule('Strong WEB', SubtitleRuleStrength::StrongEvidence, ['eng'], 'WEB'),
        ],
        rcrSonarrTarget(),
        SeasonPackPolicy::ApprovalRequired,
    );

    expect(array_column($result['candidates'], 'title'))->toBe(['Example Show CR', 'Example Show WEB'])
        ->and(array_column($result['candidates'], 'confidence'))->toBe([98, 85]);
});

test('each hard exclusion increments only its first failing gate', function (
    array $releaseOverrides,
    array $requiredLanguages,
    array $rules,
    array $targetOverrides,
    SeasonPackPolicy $seasonPackPolicy,
    string $expectedGate,
): void {
    $result = rcrRanker()->rank(
        [rcrRelease($releaseOverrides)],
        $requiredLanguages,
        $rules,
        rcrSonarrTarget($targetOverrides),
        $seasonPackPolicy,
    );

    $expectedExcluded = rcrEmptyExcluded();
    $expectedExcluded[$expectedGate] = 1;

    expect($result['candidates'])->toBe([])
        ->and($result['excluded'])->toBe($expectedExcluded)
        ->and($result['unique_best'])->toBeFalse();
})->with([
    'mismatched mapping -> mapping' => fn (): array => [
        ['episodeIds' => [999]], ['eng'], [rcrRule()], [], SeasonPackPolicy::ApprovalRequired, 'mapping',
    ],
    'downloadAllowed not strict true -> unavailable' => fn (): array => [
        ['downloadAllowed' => 1], ['eng'], [rcrRule()], [], SeasonPackPolicy::ApprovalRequired, 'unavailable',
    ],
    'non-empty rejections -> rejected' => fn (): array => [
        ['rejections' => ['Indexer rejected release']], ['eng'], [rcrRule()], [], SeasonPackPolicy::ApprovalRequired, 'rejected',
    ],
    'installed release title exact case-insensitively -> installed_release' => fn (): array => [
        ['title' => ' Example Show CR '], ['eng'], [rcrRule()], ['installed_release' => 'example show cr'], SeasonPackPolicy::ApprovalRequired, 'installed_release',
    ],
    'one required language lacks evidence -> subtitle_evidence' => fn (): array => [
        [], ['eng', 'swe'], [rcrRule()], [], SeasonPackPolicy::ApprovalRequired, 'subtitle_evidence',
    ],
    'preference-only match is not evidence -> subtitle_evidence' => fn (): array => [
        [], ['eng'], [rcrRule('Preference', SubtitleRuleStrength::Preference)], [], SeasonPackPolicy::ApprovalRequired, 'subtitle_evidence',
    ],
    'full season forbidden by policy -> season_pack_policy' => fn (): array => [
        ['fullSeason' => true], ['eng'], [rcrRule()], [], SeasonPackPolicy::Never, 'season_pack_policy',
    ],
]);

test('hard exclusions use the documented first-failing gate precedence', function (
    array $releaseOverrides,
    array $rules,
    array $targetOverrides,
    string $expectedGate,
): void {
    $result = rcrRanker()->rank(
        [rcrRelease($releaseOverrides)],
        ['eng'],
        $rules,
        rcrSonarrTarget($targetOverrides),
        SeasonPackPolicy::Never,
    );

    $expectedExcluded = rcrEmptyExcluded();
    $expectedExcluded[$expectedGate] = 1;

    expect($result['excluded'])->toBe($expectedExcluded);
})->with([
    'mapping precedes every later failure' => fn (): array => [
        [
            'episodeIds' => [999],
            'downloadAllowed' => false,
            'rejections' => ['Rejected'],
            'title' => 'Installed CR',
            'fullSeason' => true,
        ],
        [],
        ['installed_release' => 'installed cr'],
        'mapping',
    ],
    'unavailable precedes every later failure' => fn (): array => [
        [
            'downloadAllowed' => false,
            'rejections' => ['Rejected'],
            'title' => 'Installed CR',
            'fullSeason' => true,
        ],
        [],
        ['installed_release' => 'installed cr'],
        'unavailable',
    ],
    'rejected precedes every later failure' => fn (): array => [
        ['rejections' => ['Rejected'], 'title' => 'Installed CR', 'fullSeason' => true],
        [],
        ['installed_release' => 'installed cr'],
        'rejected',
    ],
    'installed release precedes evidence and season pack failures' => fn (): array => [
        ['title' => 'Installed CR', 'fullSeason' => true],
        [],
        ['installed_release' => 'installed cr'],
        'installed_release',
    ],
    'subtitle evidence precedes season pack failure' => fn (): array => [
        ['fullSeason' => true],
        [],
        [],
        'subtitle_evidence',
    ],
    'season pack is the final hard gate' => fn (): array => [
        ['fullSeason' => true],
        [rcrRule()],
        [],
        'season_pack_policy',
    ],
]);

test('eligible season packs remain candidates for non-never policies', function (SeasonPackPolicy $seasonPackPolicy): void {
    // A realistic season pack: its episode set is the whole season, a superset
    // of the single requested episode (101).
    $result = rcrRanker()->rank(
        [rcrRelease(['fullSeason' => true, 'episodeIds' => [101, 102, 103]])],
        ['eng'],
        [rcrRule()],
        rcrSonarrTarget(['episode_ids' => [101]]),
        $seasonPackPolicy,
    );

    expect($result['candidates'])->toHaveCount(1)
        ->and($result['candidates'][0]['season_pack'])->toBeTrue()
        ->and($result['excluded']['season_pack_policy'])->toBe(0);
})->with([
    'approval required' => SeasonPackPolicy::ApprovalRequired,
    'automatic above threshold' => SeasonPackPolicy::AutomaticAboveThreshold,
]);

test('a realistic season pack (superset episode set) is gated by policy, not dropped at mapping', function (): void {
    $pack = rcrRelease(['fullSeason' => true, 'episodeIds' => [101, 102, 103]]);
    $target = rcrSonarrTarget(['episode_ids' => [101]]);

    $never = rcrRanker()->rank([$pack], ['eng'], [rcrRule()], $target, SeasonPackPolicy::Never);
    $approval = rcrRanker()->rank([$pack], ['eng'], [rcrRule()], $target, SeasonPackPolicy::ApprovalRequired);

    expect($never['candidates'])->toBe([])
        ->and($never['excluded']['season_pack_policy'])->toBe(1)
        ->and($never['excluded']['mapping'])->toBe(0)
        ->and($approval['candidates'])->toHaveCount(1)
        ->and($approval['candidates'][0]['season_pack'])->toBeTrue();
});

test('a season pack that does not cover the requested episode is excluded at mapping', function (): void {
    $result = rcrRanker()->rank(
        [rcrRelease(['fullSeason' => true, 'episodeIds' => [201, 202, 203]])],
        ['eng'],
        [rcrRule()],
        rcrSonarrTarget(['episode_ids' => [101]]),
        SeasonPackPolicy::ApprovalRequired,
    );

    expect($result['candidates'])->toBe([])
        ->and($result['excluded']['mapping'])->toBe(1);
});

test('multi-language confidence uses the strongest evidence per language and then the weakest language', function (): void {
    $result = rcrRanker()->rank(
        [rcrRelease(['title' => 'Example Show CR WEB'])],
        ['English', 'Swedish'],
        [
            rcrRule('English strong', SubtitleRuleStrength::StrongEvidence, ['eng'], 'WEB'),
            rcrRule('English guarantee', SubtitleRuleStrength::Guarantee, ['en'], 'CR'),
            rcrRule('Swedish strong', SubtitleRuleStrength::StrongEvidence, ['sv'], 'WEB'),
        ],
        rcrSonarrTarget(),
        SeasonPackPolicy::ApprovalRequired,
    );

    expect($result['candidates'][0]['confidence'])->toBe(85)
        ->and($result['candidates'][0]['matched_rules'])->toHaveCount(3);
});

test('invalid utf-8 required languages are ignored when valid requirements remain', function (): void {
    $result = rcrRanker()->rank(
        [rcrRelease()],
        ['eng', "\xB1"],
        [rcrRule()],
        rcrSonarrTarget(),
        SeasonPackPolicy::ApprovalRequired,
    );

    expect($result['candidates'])->toHaveCount(1)
        ->and($result['candidates'][0]['confidence'])->toBe(98)
        ->and($result['candidates'][0]['matched_rules'][0]['languages'])->toBe(['eng'])
        ->and($result['excluded']['subtitle_evidence'])->toBe(0);
});

test('distinct invalid utf-8 required and rule languages cannot grant confidence', function (): void {
    $result = rcrRanker()->rank(
        [rcrRelease()],
        ["\xB1"],
        [rcrRule(languages: ["\xB2"])],
        rcrSonarrTarget(),
        SeasonPackPolicy::ApprovalRequired,
    );
    $expectedExcluded = rcrEmptyExcluded();
    $expectedExcluded['subtitle_evidence'] = 1;

    expect($result['candidates'])->toBe([])
        ->and($result['excluded'])->toBe($expectedExcluded)
        ->and($result['unique_best'])->toBeFalse();
});

test('preference rules improve suitability without providing confidence', function (): void {
    $result = rcrRanker()->rank(
        [
            rcrRelease(['guid' => 'plain', 'title' => 'Beta Show CR']),
            rcrRelease(['guid' => 'preferred', 'title' => 'Zulu Show CR PREFERRED']),
        ],
        ['eng'],
        [
            rcrRule('Evidence', SubtitleRuleStrength::StrongEvidence, ['eng'], 'CR', 'Subtitle evidence.'),
            rcrRule('Preferred encode', SubtitleRuleStrength::Preference, ['eng'], 'PREFERRED', 'Better encode.'),
        ],
        rcrSonarrTarget(),
        SeasonPackPolicy::ApprovalRequired,
    );

    $matchedRules = $result['candidates'][0]['matched_rules'];

    expect($result['candidates'][0]['title'])->toBe('Zulu Show CR PREFERRED')
        ->and($result['candidates'][0]['confidence'])->toBe(85)
        ->and($matchedRules)->toContain([
            'name' => 'Evidence',
            'strength' => 'strong_evidence',
            'languages' => ['eng'],
            'evidences_subtitles' => true,
            'explanation' => 'Subtitle evidence.',
        ])
        ->and($matchedRules)->toContain([
            'name' => 'Preferred encode',
            'strength' => 'preference',
            'languages' => ['eng'],
            'evidences_subtitles' => false,
            'explanation' => 'Better encode.',
        ]);
});

test('it applies every deterministic ranking field in the approved order', function (
    array $releases,
    array $rules,
    string $expectedTitle,
    bool $expectedUniqueBest,
): void {
    $result = rcrRanker()->rank(
        $releases,
        ['eng'],
        $rules,
        rcrSonarrTarget(),
        SeasonPackPolicy::ApprovalRequired,
    );

    expect($result['candidates'][0]['title'])->toBe($expectedTitle)
        ->and($result['unique_best'])->toBe($expectedUniqueBest);
})->with([
    'confidence descending' => fn (): array => [
        [
            rcrRelease(['guid' => 'strong', 'title' => 'Alpha WEB', 'customFormatScore' => 999]),
            rcrRelease(['guid' => 'guarantee', 'title' => 'Zulu CR', 'customFormatScore' => 0]),
        ],
        [
            rcrRule('Guaranteed', SubtitleRuleStrength::Guarantee, ['eng'], 'CR'),
            rcrRule('Strong', SubtitleRuleStrength::StrongEvidence, ['eng'], 'WEB'),
        ],
        'Zulu CR', true,
    ],
    'preference matches descending' => fn (): array => [
        [
            rcrRelease(['guid' => 'plain', 'title' => 'Alpha CR', 'customFormatScore' => 999]),
            rcrRelease(['guid' => 'preferred', 'title' => 'Zulu CR PREF', 'customFormatScore' => 0]),
        ],
        [
            rcrRule(),
            rcrRule('Preferred', SubtitleRuleStrength::Preference, ['eng'], 'PREF'),
        ],
        'Zulu CR PREF', true,
    ],
    'custom format score descending' => fn (): array => [
        [
            rcrRelease(['guid' => 'low', 'title' => 'Alpha CR', 'customFormatScore' => 1, 'qualityWeight' => 999]),
            rcrRelease(['guid' => 'high', 'title' => 'Zulu CR', 'customFormatScore' => 2, 'qualityWeight' => 0]),
        ],
        [rcrRule()], 'Zulu CR', true,
    ],
    'quality weight descending' => fn (): array => [
        [
            rcrRelease(['guid' => 'low', 'title' => 'Alpha CR', 'qualityWeight' => 1, 'seeders' => 999]),
            rcrRelease(['guid' => 'high', 'title' => 'Zulu CR', 'qualityWeight' => 2, 'seeders' => 0]),
        ],
        [rcrRule()], 'Zulu CR', true,
    ],
    'seeders descending' => fn (): array => [
        [
            rcrRelease(['guid' => 'low', 'title' => 'Alpha CR', 'seeders' => 1, 'ageMinutes' => 0]),
            rcrRelease(['guid' => 'high', 'title' => 'Zulu CR', 'seeders' => 2, 'ageMinutes' => 999]),
        ],
        [rcrRule()], 'Zulu CR', true,
    ],
    'age minutes ascending' => fn (): array => [
        [
            rcrRelease(['guid' => 'old', 'title' => 'Alpha CR', 'ageMinutes' => 2]),
            rcrRelease(['guid' => 'new', 'title' => 'Zulu CR', 'ageMinutes' => 1]),
        ],
        [rcrRule()], 'Zulu CR', true,
    ],
    'title case-insensitive ascending as stable final key' => fn (): array => [
        [
            rcrRelease(['guid' => 'beta', 'title' => 'beta CR']),
            rcrRelease(['guid' => 'alpha', 'title' => 'Alpha CR']),
        ],
        [rcrRule()], 'Alpha CR', false,
    ],
]);

test('unique best has explicit empty and single candidate semantics', function (): void {
    $empty = rcrRanker()->rank([], ['eng'], [rcrRule()], rcrSonarrTarget(), SeasonPackPolicy::ApprovalRequired);
    $single = rcrRanker()->rank([rcrRelease()], ['eng'], [rcrRule()], rcrSonarrTarget(), SeasonPackPolicy::ApprovalRequired);

    expect($empty['unique_best'])->toBeFalse()
        ->and($single['unique_best'])->toBeTrue();
});

test('numeric representation differences do not make a substantive tie unique', function (): void {
    $result = rcrRanker()->rank(
        [
            rcrRelease(['guid' => 'integer', 'title' => 'Alpha CR', 'customFormatScore' => 10]),
            rcrRelease(['guid' => 'float', 'title' => 'Zulu CR', 'customFormatScore' => 10.0]),
        ],
        ['eng'],
        [rcrRule()],
        rcrSonarrTarget(),
        SeasonPackPolicy::ApprovalRequired,
    );

    expect($result['unique_best'])->toBeFalse();
});

test('title ordering case folds valid utf-8 before comparing', function (): void {
    $result = rcrRanker()->rank(
        [
            rcrRelease(['guid' => 'upper-beta', 'title' => 'Å Beta CR']),
            rcrRelease(['guid' => 'lower-alpha', 'title' => 'å alpha CR']),
        ],
        ['eng'],
        [rcrRule()],
        rcrSonarrTarget(),
        SeasonPackPolicy::ApprovalRequired,
    );

    expect(array_column($result['candidates'], 'title'))->toBe(['å alpha CR', 'Å Beta CR'])
        ->and($result['unique_best'])->toBeFalse();
});

test('title ordering uses a deterministic raw fallback when utf-8 folds are equal', function (): void {
    $upperTitle = rcrRelease(['guid' => 'upper', 'title' => 'Å CR']);
    $lowerTitle = rcrRelease(['guid' => 'lower', 'title' => 'å CR']);
    $rank = fn (array $releases): array => rcrRanker()->rank(
        $releases,
        ['eng'],
        [rcrRule()],
        rcrSonarrTarget(),
        SeasonPackPolicy::ApprovalRequired,
    );

    $forward = $rank([$lowerTitle, $upperTitle]);
    $reverse = $rank([$upperTitle, $lowerTitle]);

    expect(array_column($forward['candidates'], 'title'))->toBe(['Å CR', 'å CR'])
        ->and(array_column($reverse['candidates'], 'title'))->toBe(['Å CR', 'å CR'])
        ->and($forward['unique_best'])->toBeFalse()
        ->and($reverse['unique_best'])->toBeFalse();
});

test('requested candidate limit is clamped between one and ten', function (int $limit, int $expectedCount): void {
    $releases = [];

    for ($index = 1; $index <= 20; $index++) {
        $releases[] = rcrRelease([
            'guid' => 'guid-'.$index,
            'indexerId' => $index,
            'title' => 'Example CR '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
        ]);
    }

    $result = rcrRanker()->rank(
        $releases,
        ['eng'],
        [rcrRule()],
        rcrSonarrTarget(),
        SeasonPackPolicy::ApprovalRequired,
        $limit,
    );

    expect($result['candidates'])->toHaveCount($expectedCount);
})->with([
    'upper clamp' => [99, 10],
    'lower clamp' => [0, 1],
    'negative lower clamp' => [-5, 1],
]);

test('candidate projection is an exact safe whitelist with compact auditable evidence', function (): void {
    $release = rcrRelease([
        'episodeIds' => [102, '101', 102],
        'downloadUrl' => 'https://secret.example/download',
        'magnetUrl' => 'magnet:?xt=secret',
        'servicePayload' => ['apiKey' => 'secret'],
    ]);

    $result = rcrRanker()->rank(
        [$release],
        ['eng'],
        [rcrRule()],
        rcrSonarrTarget(['episode_ids' => [101, 102]]),
        SeasonPackPolicy::ApprovalRequired,
    );

    $candidate = $result['candidates'][0];

    expect(array_keys($candidate))->toBe([
        'fingerprint',
        'title',
        'release_group',
        'subgroup',
        'mapped_ids',
        'quality',
        'size',
        'age',
        'seeders',
        'custom_format_score',
        'confidence',
        'matched_rules',
        'season_pack',
    ])->and($candidate)->toMatchArray([
        'title' => 'Example.Show.S01E01.CR.1080p',
        'release_group' => 'SubsPlease',
        'subgroup' => 'CR',
        'mapped_ids' => [101, 102],
        'quality' => 'Bluray-1080p',
        'size' => 1_500_000_000,
        'age' => 60,
        'seeders' => 5,
        'custom_format_score' => 10,
        'confidence' => 98,
        'season_pack' => false,
    ])->and($candidate)->not->toHaveKeys(['downloadUrl', 'magnetUrl', 'servicePayload']);
});

test('malformed numeric strings default safely without distorting ranking fields', function (): void {
    $result = rcrRanker()->rank(
        [rcrRelease([
            'customFormatScore' => '1e308',
            'qualityWeight' => '1e308',
            'size' => '1e308',
            'seeders' => '1e308',
            'ageMinutes' => '1e308',
        ])],
        ['eng'],
        [rcrRule()],
        rcrSonarrTarget(),
        SeasonPackPolicy::ApprovalRequired,
    );

    expect($result['candidates'][0])->toMatchArray([
        'custom_format_score' => 0,
        'size' => 0,
        'seeders' => 0,
        'age' => 0,
    ]);
});

test('invalid utf-8 identity fields are safely defaulted before fingerprinting', function (): void {
    $releaseGroupRule = rcrRule();
    $releaseGroupRule['conditions'] = [['field' => 'release_group', 'value' => 'SubsPlease']];

    $result = rcrRanker()->rank(
        [rcrRelease(['title' => "\xB1CR"])],
        ['eng'],
        [$releaseGroupRule],
        rcrSonarrTarget(),
        SeasonPackPolicy::ApprovalRequired,
    );

    expect($result['candidates'])->toHaveCount(1)
        ->and($result['candidates'][0]['title'])->toBe('')
        ->and($result['candidates'][0]['fingerprint'])->toHaveLength(64);
});

test('all externally sourced projected strings remain json serializable', function (
    mixed $ruleName,
    mixed $ruleExplanation,
    mixed $quality,
): void {
    $rule = rcrRule();
    $rule['name'] = $ruleName;
    $rule['explanation'] = $ruleExplanation;
    $release = rcrRelease([
        'releaseGroup' => "\xB1Group",
        'subGroup' => "\xB1Subgroup",
        'quality' => $quality,
    ]);

    $result = rcrRanker()->rank(
        [$release],
        ['eng'],
        [$rule],
        rcrSonarrTarget(),
        SeasonPackPolicy::ApprovalRequired,
    );
    $candidate = $result['candidates'][0];
    $matchedRule = $candidate['matched_rules'][0];
    $encodedResult = json_encode($result, JSON_THROW_ON_ERROR);

    expect($encodedResult)->toBeString()
        ->and($candidate['release_group'])->toBe('')
        ->and($candidate['subgroup'])->toBe('')
        ->and($candidate['quality'])->toBeNull()
        ->and($matchedRule['name'])->toBe('Untitled rule')
        ->and($matchedRule)->not->toHaveKey('explanation');
})->with([
    'invalid utf-8 scalar quality' => ["\xB1Rule", "\xB1Explanation", "\xB1Quality"],
    'invalid utf-8 nested quality name' => [
        "\xB1Rule",
        "\xB1Explanation",
        ['quality' => ['name' => "\xB1Quality"]],
    ],
    'malformed non-string metadata' => [
        ['invalid'],
        (object) ['invalid' => true],
        ['quality' => ['name' => ['invalid']]],
    ],
]);

test('valid utf-8 projected strings are preserved exactly', function (): void {
    $rule = rcrRule();
    $rule['name'] = '  Pålitlig regel ✓  ';
    $rule['explanation'] = '  Förklaring ✓  ';
    $release = rcrRelease([
        'releaseGroup' => '  Grupp ✓  ',
        'subGroup' => '  Undergrupp ✓  ',
        'quality' => ['quality' => ['name' => '  Kvalitet ✓  ']],
    ]);

    $result = rcrRanker()->rank(
        [$release],
        ['eng'],
        [$rule],
        rcrSonarrTarget(),
        SeasonPackPolicy::ApprovalRequired,
    );
    $candidate = $result['candidates'][0];
    $matchedRule = $candidate['matched_rules'][0];

    expect($candidate['release_group'])->toBe('  Grupp ✓  ')
        ->and($candidate['subgroup'])->toBe('  Undergrupp ✓  ')
        ->and($candidate['quality'])->toBe('  Kvalitet ✓  ')
        ->and($matchedRule['name'])->toBe('  Pålitlig regel ✓  ')
        ->and($matchedRule['explanation'])->toBe('  Förklaring ✓  ');
});

test('fingerprints hash only normalized stable release identity fields', function (): void {
    $fingerprint = new ReleaseFingerprint;
    $release = rcrRelease(['episodeIds' => [102, '101', 102]]);
    $transientlyChanged = array_replace($release, [
        'customFormatScore' => 999,
        'seeders' => 999,
        'ageMinutes' => 1,
        'downloadUrl' => 'https://changed.example/download',
    ]);
    $expected = hash('sha256', json_encode([
        'service' => 'sonarr',
        'indexer_id' => 10,
        'guid' => 'guid-1',
        'title' => 'Example.Show.S01E01.CR.1080p',
        'mapped_ids' => [101, 102],
    ], JSON_THROW_ON_ERROR));

    expect($fingerprint->make('sonarr', $release))->toBe($expected)
        ->and($fingerprint->make('sonarr', $transientlyChanged))->toBe($expected);
});

test('changing a stable identity field changes the fingerprint', function (string $service, array $overrides): void {
    $fingerprint = new ReleaseFingerprint;

    expect($fingerprint->make($service, rcrRelease($overrides)))
        ->not->toBe($fingerprint->make('sonarr', rcrRelease()));
})->with([
    'service' => ['radarr', []],
    'indexer id' => ['sonarr', ['indexerId' => 11]],
    'guid' => ['sonarr', ['guid' => 'different']],
    'title' => ['sonarr', ['title' => 'Different CR']],
    'mapped ids' => ['sonarr', ['episodeIds' => [102]]],
]);

test('radarr fingerprints use movie identity even when an extraneous episode list is present', function (): void {
    $fingerprint = new ReleaseFingerprint;
    $firstMovie = rcrRelease(['movieId' => 42, 'episodeIds' => [101]]);
    $secondMovie = rcrRelease(['movieId' => 43, 'episodeIds' => [101]]);

    expect($fingerprint->make('radarr', $firstMovie))
        ->not->toBe($fingerprint->make('radarr', $secondMovie));
});

test('overflowing mapped ids fail closed instead of collapsing to the same integer', function (): void {
    $result = rcrRanker()->rank(
        [rcrRelease(['episodeIds' => ['9223372036854775808']])],
        ['eng'],
        [rcrRule()],
        rcrSonarrTarget(['episode_ids' => ['9223372036854775809']]),
        SeasonPackPolicy::ApprovalRequired,
    );

    $expectedExcluded = rcrEmptyExcluded();
    $expectedExcluded['mapping'] = 1;

    expect($result['candidates'])->toBe([])
        ->and($result['excluded'])->toBe($expectedExcluded);
});

test('sonarr and radarr mappings normalize integer identities safely', function (array $release, array $target): void {
    $result = rcrRanker()->rank(
        [$release],
        ['eng'],
        [rcrRule()],
        $target,
        SeasonPackPolicy::ApprovalRequired,
    );

    expect($result['candidates'])->toHaveCount(1)
        ->and($result['excluded'])->toBe(rcrEmptyExcluded());
})->with([
    'sonarr ignores id input order and duplicates' => fn (): array => [
        rcrRelease(['episodeIds' => [102, '101', 102]]),
        rcrSonarrTarget(['episode_ids' => [101, 102, 101]]),
    ],
    'radarr compares normalized movie id' => function (): array {
        $release = rcrRelease(['movieId' => '42']);
        unset($release['episodeIds']);

        return [$release, ['service' => 'radarr', 'movie_id' => 42, 'installed_release' => 'installed CR']];
    },
]);

test('unknown services and invalid target mappings exclude safely', function (array $target): void {
    $result = rcrRanker()->rank(
        [rcrRelease()],
        ['eng'],
        [rcrRule()],
        $target,
        SeasonPackPolicy::ApprovalRequired,
    );

    $expectedExcluded = rcrEmptyExcluded();
    $expectedExcluded['mapping'] = 1;

    expect($result['candidates'])->toBe([])
        ->and($result['excluded'])->toBe($expectedExcluded);
})->with([
    'unsupported service' => [['service' => 'lidarr', 'episode_ids' => [101]]],
    'missing service' => [['episode_ids' => [101]]],
    'invalid sonarr ids' => [['service' => 'sonarr', 'episode_ids' => ['invalid']]],
    'partially invalid sonarr ids' => [['service' => 'sonarr', 'episode_ids' => [101, 'invalid']]],
    'missing radarr id' => [['service' => 'radarr']],
]);

test('malformed rows and rules fail closed without warnings or type errors', function (): void {
    $result = rcrRanker()->rank(
        [
            'not an array',
            123,
            [],
            rcrRelease(['title' => ['not', 'a', 'string']]),
        ],
        ['eng', null, 123, ''],
        [
            'not an array',
            null,
            array_replace(rcrRule('Malformed', SubtitleRuleStrength::Guarantee, ['eng'], 'CR'), ['conditions' => 'invalid']),
        ],
        rcrSonarrTarget(),
        SeasonPackPolicy::ApprovalRequired,
    );

    expect($result['candidates'])->toBe([])
        ->and($result['excluded'])->toBe([
            'mapping' => 3,
            'unavailable' => 0,
            'rejected' => 0,
            'installed_release' => 0,
            'subtitle_evidence' => 1,
            'season_pack_policy' => 0,
        ]);
});
