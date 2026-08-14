<?php

declare(strict_types=1);

use App\Services\MediaReplacement\MediaReplacementTargetFingerprint;

test('fingerprint is stable for equivalent snapshots and file-id order', function (): void {
    $a = MediaReplacementTargetFingerprint::fromSnapshot([
        'service' => 'sonarr', 'service_connection_id' => 3, 'series_id' => 42,
        'season_number' => 1, 'episode_numbers' => [2], 'episode_file_ids' => [9, 7],
    ]);
    $b = MediaReplacementTargetFingerprint::fromSnapshot([
        'episode_file_ids' => [7, 9], 'series_id' => 42, 'season_number' => 1,
        'episode_numbers' => [2], 'service' => 'sonarr', 'service_connection_id' => 3,
    ]);

    expect($a)->toBe($b)->and($a)->toHaveLength(64);
});

test('fingerprint changes when the installed file changes', function (): void {
    $base = ['service' => 'radarr', 'service_connection_id' => 1, 'movie_id' => 10, 'movie_file_ids' => [5]];

    expect(MediaReplacementTargetFingerprint::fromSnapshot($base))
        ->not->toBe(MediaReplacementTargetFingerprint::fromSnapshot([...$base, 'movie_file_ids' => [6]]));
});

test('fingerprint changes when the connection changes', function (): void {
    $base = ['service' => 'radarr', 'service_connection_id' => 1, 'movie_id' => 10, 'movie_file_ids' => [5]];

    expect(MediaReplacementTargetFingerprint::fromSnapshot($base))
        ->not->toBe(MediaReplacementTargetFingerprint::fromSnapshot([...$base, 'service_connection_id' => 2]));
});
