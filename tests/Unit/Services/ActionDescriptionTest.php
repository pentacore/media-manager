<?php

declare(strict_types=1);

use App\Services\Actions\ActionDescription;
use App\Services\Actions\ActionTarget;

test('an action description trims and keeps its parts', function (): void {
    $description = new ActionDescription('  Delete series "Severance"  ', ' Sonarr will delete it. ', [
        ['label' => 'Series', 'value' => 'Severance'],
    ]);

    expect($description->title)->toBe('Delete series "Severance"')
        ->and($description->description)->toBe('Sonarr will delete it.')
        ->and($description->details)->toBe([['label' => 'Series', 'value' => 'Severance']])
        ->and($description->verified)->toBeTrue();
});

test('an action description rejects an empty title or description', function (string $title, string $text): void {
    new ActionDescription($title, $text);
})->with([
    'empty title' => ['   ', 'Something happens.'],
    'empty description' => ['Title', '  '],
])->throws(InvalidArgumentException::class);

test('because prepends the reason to the effect sentence', function (): void {
    $actionDescription = new ActionDescription('Scan the Emby library', 'Emby will rescan its libraries.')
        ->because('Radarr imported "Dune".');

    expect($actionDescription->description)->toBe('Radarr imported "Dune". Emby will rescan its libraries.');
});

test('withDetail formats booleans and drops empty values', function (): void {
    $actionDescription = new ActionDescription('T', 'D')
        ->withDetail('Delete files', true)
        ->withDetail('Monitored', false)
        ->withDetail('Sonarr ID', 142)
        ->withDetail('Root folder', null)
        ->withDetail('Notes', '   ');

    expect($actionDescription->details)->toBe([
        ['label' => 'Delete files', 'value' => 'Yes'],
        ['label' => 'Monitored', 'value' => 'No'],
        ['label' => 'Sonarr ID', 'value' => '142'],
    ]);
});

test('an action description bounds every string and the detail count', function (): void {
    $details = array_map(
        static fn (int $index): array => ['label' => str_repeat('L', 150), 'value' => str_repeat('V', 600).$index],
        range(1, 25),
    );

    $description = new ActionDescription(str_repeat('T', 400), str_repeat('D', 1200), $details);

    expect(mb_strlen($description->title))->toBe(ActionDescription::TITLE_LIMIT)
        ->and(mb_strlen($description->description))->toBe(ActionDescription::DESCRIPTION_LIMIT)
        ->and($description->details)->toHaveCount(ActionDescription::DETAIL_LIMIT)
        ->and(mb_strlen($description->details[0]['label']))->toBe(ActionDescription::LABEL_LIMIT)
        ->and(mb_strlen($description->details[0]['value']))->toBe(ActionDescription::VALUE_LIMIT);
});

test('unverified keeps content and flips the flag', function (): void {
    $actionDescription = new ActionDescription('T', 'D', [['label' => 'A', 'value' => 'B']])->unverified();

    expect($actionDescription->verified)->toBeFalse()
        ->and($actionDescription->toAttributes())->toBe([
            'title' => 'T',
            'description' => 'D',
            'details' => [['label' => 'A', 'value' => 'B']],
            'description_verified' => false,
        ]);
});

test('an action target labels itself and seeds a description with its facts', function (): void {
    $target = new ActionTarget('series', 'Severance (2022)', [['label' => 'Sonarr ID', 'value' => '142']], verified: false);

    $actionDescription = $target->describe(sprintf('Delete %s', $target->label()), 'Sonarr will delete the series.');

    expect($target->label())->toBe('series "Severance (2022)"')
        ->and($actionDescription->title)->toBe('Delete series "Severance (2022)"')
        ->and($actionDescription->details)->toBe([['label' => 'Sonarr ID', 'value' => '142']])
        ->and($actionDescription->verified)->toBeFalse();
});
