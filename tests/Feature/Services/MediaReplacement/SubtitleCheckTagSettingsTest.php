<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Services\MediaReplacement\SubtitleCheckTagSettings;

test('it returns the configured labels lowercased and deduplicated', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'settings' => ['subtitle_check_tags' => ['Sub-Check', 'sub-check', '  Anime  ', '']],
    ]);

    expect(resolve(SubtitleCheckTagSettings::class)->forConnection($connection))
        ->toBe(['sub-check', 'anime']);
});

test('it returns an empty list when nothing is configured', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create(['settings' => []]);

    expect(resolve(SubtitleCheckTagSettings::class)->forConnection($connection))->toBe([]);
});

test('it tolerates a malformed stored value', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'settings' => ['subtitle_check_tags' => 'not-a-list'],
    ]);

    expect(resolve(SubtitleCheckTagSettings::class)->forConnection($connection))->toBe([]);
});

test('mergeInto normalizes without disturbing sibling settings', function (): void {
    $merged = resolve(SubtitleCheckTagSettings::class)->mergeInto(
        ['sonarr_root_folders' => [['root_folder_id' => 1, 'path' => '/tv', 'scope' => 'tv']]],
        ['Sub-Check', 'Sub-Check', 42, null],
    );

    expect($merged['subtitle_check_tags'])->toBe(['sub-check'])
        ->and($merged['sonarr_root_folders'])->toHaveCount(1);
});
