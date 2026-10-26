<?php

declare(strict_types=1);

use App\Enums\MediaReplacementScope;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\MediaReplacement\MediaFileInspector;
use App\Services\MediaReplacement\SubtitleCheckRules;
use App\Settings\MediaReplacementSettings;

beforeEach(function (): void {
    resolve(MediaReplacementSettings::class)->setConfiguration([
        'automatic_selection_enabled' => true,
        'automatic_selection_threshold' => 90,
        'global_languages' => ['English'],
        'scoped_languages' => ['anime' => null, 'tv' => null, 'movie' => null],
        'season_pack_policy' => 'allowed',
        'subtitle_check' => ['enabled' => true, 'max_attempts_per_target' => 1, 'cooldown_hours' => 24],
        'guidance' => [
            'anime' => ['notes' => '', 'rules' => []],
            'tv' => ['notes' => '', 'rules' => []],
            'movie' => ['notes' => '', 'rules' => []],
        ],
    ]);
});

test('missing languages are the required ones the file does not carry', function (): void {
    $subtitleCheckRules = resolve(SubtitleCheckRules::class);

    expect($subtitleCheckRules->missingLanguages(['Japanese'], MediaReplacementScope::Anime))->toBe(['eng'])
        ->and($subtitleCheckRules->missingLanguages(['English', 'Japanese'], MediaReplacementScope::Anime))->toBe([]);
});

test('the auto check key matches the import check format', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();

    $key = resolve(SubtitleCheckRules::class)->autoCheckKey($connection, [
        'series_id' => 42,
        'episode_ids' => [102, 101],
    ]);

    expect($key)->toBe(sprintf('sonarr:%d:42-101-102', $connection->id));
});

test('the cap counts replace requests for the key inside the cooldown', function (): void {
    $key = 'sonarr:1:42-101';

    ActionRequest::factory()->create([
        'type' => 'replace_media_file',
        'payload' => ['auto_check_key' => $key],
        'created_at' => now(),
    ]);

    $subtitleCheckRules = resolve(SubtitleCheckRules::class);

    expect($subtitleCheckRules->capReached($key))->toBeTrue();

    ActionRequest::query()->update(['created_at' => now()->subHours(25)]);

    expect($subtitleCheckRules->capReached($key))->toBeFalse();
});

test('a raw subtitle string and array normalize alike', function (): void {
    $mediaFileInspector = resolve(MediaFileInspector::class);

    $fromString = $mediaFileInspector->subtitleLanguages('eng/jpn');

    expect($fromString)->toBe(['eng', 'jpn'])
        ->and($mediaFileInspector->subtitleLanguages(['eng', 'jpn']))->toBe($fromString)
        ->and($mediaFileInspector->subtitleLanguages(['English', 'Japanese']))->toBe($fromString)
        ->and($mediaFileInspector->subtitleLanguages('English, Japanese'))->toBe($fromString);
});
