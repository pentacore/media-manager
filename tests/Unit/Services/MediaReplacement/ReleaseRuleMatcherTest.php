<?php

declare(strict_types=1);

use App\Enums\SubtitleRuleStrength;
use App\Services\MediaReplacement\ReleaseRuleMatcher;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rrmTrustedRule(array $overrides = []): array
{
    return array_replace([
        'name' => 'Trusted CR release',
        'enabled' => true,
        'strength' => SubtitleRuleStrength::Guarantee->value,
        'languages' => ['eng'],
        'conditions' => [
            ['field' => 'title', 'value' => 'CR'],
        ],
        'explanation' => 'Trusted release tag.',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rrmRelease(array $overrides = []): array
{
    return array_replace([
        'title' => 'Example.Show.S01E01.[CR].1080p',
        'releaseGroup' => ' SubsPlease ',
        'subGroup' => ' Erai-Raws ',
        'customFormats' => [
            ['name' => 'Dual Audio'],
            (object) ['name' => 'Trusted Subs'],
        ],
    ], $overrides);
}

test('it requires every supported condition on a rule to match', function (): void {
    $rule = rrmTrustedRule([
        'conditions' => [
            ['field' => 'release_group', 'value' => 'subsplease'],
            ['field' => 'subgroup', 'value' => 'ERAI-RAWS'],
            ['field' => 'title', 'value' => 'Example Show'],
            ['field' => 'custom_format', 'value' => ' trusted subs '],
        ],
    ]);

    $matcher = new ReleaseRuleMatcher;

    expect($matcher->matches($rule, rrmRelease()))->toBeTrue()
        ->and($matcher->matches($rule, rrmRelease(['subGroup' => 'Other'])))->toBeFalse();
});

test('it matches exact group subgroup and custom format values case insensitively after trimming', function (string $field, string $value): void {
    $matcher = new ReleaseRuleMatcher;

    expect($matcher->matches(
        rrmTrustedRule(['conditions' => [['field' => $field, 'value' => $value]]]),
        rrmRelease(),
    ))->toBeTrue();
})->with([
    'release group' => ['release_group', '  SUBSPLEASE '],
    'subgroup' => ['subgroup', ' erai-raws  '],
    'array custom format' => ['custom_format', ' dual audio '],
    'object custom format' => ['custom_format', ' TRUSTED SUBS '],
]);

test('it matches bounded title tokens and literal phrases without treating configured text as regex', function (string $needle, string $title, bool $expected): void {
    $matcher = new ReleaseRuleMatcher;

    expect($matcher->matches(
        rrmTrustedRule(['conditions' => [['field' => 'title', 'value' => $needle]]]),
        rrmRelease(['title' => $title]),
    ))->toBe($expected);
})->with([
    'bracketed token' => ['CR', 'Example Show [CR] 1080p', true],
    'separator bounded token' => ['CR', 'Example.Show-CR_1080p', true],
    'case-insensitive literal phrase' => ['trusted subtitle release', 'Show.Trusted Subtitle Release.1080p', true],
    'token cannot match a larger word' => ['CR', 'Example.SCREEN.1080p', false],
    'regex metacharacters are literal' => ['[CR]', 'Example Show [CR] 1080p', true],
]);

test('it rejects disabled or structurally invalid rules', function (array $rule): void {
    expect((new ReleaseRuleMatcher)->matches($rule, rrmRelease()))->toBeFalse();
})->with([
    'disabled' => fn (): array => rrmTrustedRule(['enabled' => false]),
    'truthy enabled is not enabled' => fn (): array => rrmTrustedRule(['enabled' => 1]),
    'missing languages' => function (): array {
        $rule = rrmTrustedRule();
        unset($rule['languages']);

        return $rule;
    },
    'empty languages' => fn (): array => rrmTrustedRule(['languages' => []]),
    'languages contain no valid strings' => fn (): array => rrmTrustedRule(['languages' => [null, 123, '']]),
    'missing strength' => function (): array {
        $rule = rrmTrustedRule();
        unset($rule['strength']);

        return $rule;
    },
    'invalid strength' => fn (): array => rrmTrustedRule(['strength' => 'absolute']),
    'non-string strength' => fn (): array => rrmTrustedRule(['strength' => 98]),
    'missing conditions' => function (): array {
        $rule = rrmTrustedRule();
        unset($rule['conditions']);

        return $rule;
    },
    'empty conditions' => fn (): array => rrmTrustedRule(['conditions' => []]),
    'condition missing field' => fn (): array => rrmTrustedRule(['conditions' => [['value' => 'CR']]]),
    'condition missing value' => fn (): array => rrmTrustedRule(['conditions' => [['field' => 'title']]]),
    'condition has empty field' => fn (): array => rrmTrustedRule(['conditions' => [['field' => '', 'value' => 'CR']]]),
    'condition has empty value' => fn (): array => rrmTrustedRule(['conditions' => [['field' => 'title', 'value' => '']]]),
    'condition is not an array' => fn (): array => rrmTrustedRule(['conditions' => ['title:CR']]),
    'unknown condition field' => fn (): array => rrmTrustedRule(['conditions' => [['field' => 'indexer', 'value' => 'CR']]]),
]);

test('it returns false when a single condition in an otherwise matching rule fails', function (): void {
    $rule = rrmTrustedRule([
        'conditions' => [
            ['field' => 'title', 'value' => 'CR'],
            ['field' => 'release_group', 'value' => 'Wrong Group'],
        ],
    ]);

    expect((new ReleaseRuleMatcher)->matches($rule, rrmRelease()))->toBeFalse();
});

test('it fails closed when match operands contain invalid utf-8', function (string $field, array $releaseOverrides): void {
    $rule = rrmTrustedRule([
        'conditions' => [['field' => $field, 'value' => "\xB2"]],
    ]);

    expect((new ReleaseRuleMatcher)->matches($rule, rrmRelease($releaseOverrides)))->toBeFalse();
})->with([
    'release group' => ['release_group', ['releaseGroup' => "\xB1"]],
    'subgroup' => ['subgroup', ['subGroup' => "\xB1"]],
    'title' => ['title', ['title' => "\xB1"]],
    'custom format' => ['custom_format', ['customFormats' => [['name' => "\xB1"]]]],
]);

test('it fails closed when configured condition values contain invalid utf-8', function (string $field): void {
    $rule = rrmTrustedRule([
        'conditions' => [['field' => $field, 'value' => "\xB1"]],
    ]);

    expect((new ReleaseRuleMatcher)->matches($rule, rrmRelease()))->toBeFalse();
})->with([
    'release group' => ['release_group'],
    'subgroup' => ['subgroup'],
    'title' => ['title'],
    'custom format' => ['custom_format'],
]);
