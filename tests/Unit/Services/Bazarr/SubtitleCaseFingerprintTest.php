<?php

declare(strict_types=1);

use App\Services\Bazarr\SubtitleCaseFingerprint;
use App\Services\MediaReplacement\LanguageNormalizer;

beforeEach(function (): void {
    $this->fingerprint = new SubtitleCaseFingerprint(new LanguageNormalizer);
    $this->identity = [
        'service' => 'sonarr',
        'service_connection_id' => 4,
        'file_ids' => [501],
        'media_ids' => [101, 102],
        'size' => 734003200,
        'date_added' => '2026-07-16T08:00:00Z',
        'scene_name' => 'Group.Show.S01E01-E02',
    ];
});

test('file identities are deterministic and ignore non-material raw fields', function (): void {
    $reordered = array_reverse($this->identity, true);
    $reordered['file_ids'] = [501, 501];
    $reordered['media_ids'] = [102, 101];
    $reordered['path'] = '/private/media/show.mkv';
    $reordered['title'] = 'Mutable title';
    $reordered['status'] = 'searching';

    expect($this->fingerprint->file($reordered))->toBe($this->fingerprint->file($this->identity));
});

test('every material file attribute changes the identity', function (string $key, mixed $value): void {
    $changed = [...$this->identity, $key => $value];

    expect($this->fingerprint->file($changed))->not->toBe($this->fingerprint->file($this->identity));
})->with([
    ['service_connection_id', 5],
    ['file_ids', [502]],
    ['media_ids', [103]],
    ['size', 800000000],
    ['date_added', '2026-07-17T08:00:00Z'],
    ['scene_name', 'Other.Group.Show.S01E01-E02'],
]);

test('requirements normalize language aliases order duplicates and case', function (): void {
    expect($this->fingerprint->requirements('ANIME', ['Swedish', 'EN', 'swedish']))
        ->toBe($this->fingerprint->requirements('anime', ['eng', 'swe']));
});

test('shared episode files have one identity regardless of media id order', function (): void {
    $first = [...$this->identity, 'media_ids' => [101, 102]];
    $second = [...$this->identity, 'media_ids' => [102, 101]];

    expect($this->fingerprint->file($first))->toBe($this->fingerprint->file($second));
});
