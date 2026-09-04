<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Enums\MediaReplacementStatus;
use App\Models\ActionRequest;
use App\Models\MediaReplacementAttempt;
use App\Services\MediaReplacement\PendingReplacementGuard;

/**
 * @return array<string, mixed>
 */
function replacementGuardTarget(): array
{
    return ['service' => 'radarr', 'service_connection_id' => 1, 'movie_id' => 10];
}

/**
 * @return array<string, mixed>
 */
function replacementGuardEpisodeTarget(array $overrides = []): array
{
    return [
        'service' => 'sonarr',
        'service_connection_id' => 1,
        'series_id' => 42,
        'season_number' => 1,
        'episode_numbers' => [1],
        ...$overrides,
    ];
}

test('no in-flight work means not blocked', function (): void {
    expect(resolve(PendingReplacementGuard::class)->inFlightFor(replacementGuardTarget()))->toBeFalse();
});

test('a non-terminal attempt for the same target blocks', function (): void {
    MediaReplacementAttempt::factory()->create([
        'status' => MediaReplacementStatus::Downloading,
        'target' => replacementGuardTarget(),
    ]);

    expect(resolve(PendingReplacementGuard::class)->inFlightFor(replacementGuardTarget()))->toBeTrue();
});

test('a terminal attempt does not block', function (): void {
    MediaReplacementAttempt::factory()->create([
        'status' => MediaReplacementStatus::Verified,
        'target' => replacementGuardTarget(),
    ]);

    expect(resolve(PendingReplacementGuard::class)->inFlightFor(replacementGuardTarget()))->toBeFalse();
});

test('a pending replace_media_file request for the same target blocks', function (): void {
    ActionRequest::factory()->create([
        'type' => 'replace_media_file', // the column is `type`, not `action_type`
        'status' => ActionRequestStatus::Pending,
        'payload' => ['target' => replacementGuardTarget(), 'service_connection_id' => 1],
    ]);

    expect(resolve(PendingReplacementGuard::class)->inFlightFor(replacementGuardTarget()))->toBeTrue();
});

test('approved and executing replace_media_file requests also block', function (ActionRequestStatus $status): void {
    ActionRequest::factory()->create([
        'type' => 'replace_media_file',
        'status' => $status,
        'payload' => ['target' => replacementGuardTarget(), 'service_connection_id' => 1],
    ]);

    expect(resolve(PendingReplacementGuard::class)->inFlightFor(replacementGuardTarget()))->toBeTrue();
})->with([ActionRequestStatus::Approved, ActionRequestStatus::Executing]);

test('completed, failed, and rejected replace_media_file requests do not block', function (ActionRequestStatus $status): void {
    ActionRequest::factory()->create([
        'type' => 'replace_media_file',
        'status' => $status,
        'payload' => ['target' => replacementGuardTarget(), 'service_connection_id' => 1],
    ]);

    expect(resolve(PendingReplacementGuard::class)->inFlightFor(replacementGuardTarget()))->toBeFalse();
})->with([ActionRequestStatus::Completed, ActionRequestStatus::Failed, ActionRequestStatus::Rejected]);

test('a non-terminal attempt on a different connection for the same movie does not block', function (): void {
    MediaReplacementAttempt::factory()->create([
        'status' => MediaReplacementStatus::Downloading,
        'target' => [...replacementGuardTarget(), 'service_connection_id' => 2],
    ]);

    expect(resolve(PendingReplacementGuard::class)->inFlightFor(replacementGuardTarget()))->toBeFalse();
});

test('a non-terminal attempt for a different movie on the same connection does not block', function (): void {
    MediaReplacementAttempt::factory()->create([
        'status' => MediaReplacementStatus::Downloading,
        'target' => [...replacementGuardTarget(), 'movie_id' => 11],
    ]);

    expect(resolve(PendingReplacementGuard::class)->inFlightFor(replacementGuardTarget()))->toBeFalse();
});

test('a non-terminal attempt for the same series/season/episode blocks', function (): void {
    MediaReplacementAttempt::factory()->create([
        'status' => MediaReplacementStatus::Downloading,
        'target' => replacementGuardEpisodeTarget(),
    ]);

    expect(resolve(PendingReplacementGuard::class)->inFlightFor(replacementGuardEpisodeTarget()))->toBeTrue();
});

test('a non-terminal attempt for a sibling episode in the same season does not block', function (): void {
    // Season alone would false-block every other episode of the series; episode
    // identity (episode_numbers) must be part of the match.
    MediaReplacementAttempt::factory()->create([
        'status' => MediaReplacementStatus::Downloading,
        'target' => replacementGuardEpisodeTarget(['episode_numbers' => [2]]),
    ]);

    expect(resolve(PendingReplacementGuard::class)->inFlightFor(replacementGuardEpisodeTarget(['episode_numbers' => [1]])))->toBeFalse();
});

test('a pending replace_media_file request for the same episode blocks, but a sibling episode does not', function (): void {
    ActionRequest::factory()->create([
        'type' => 'replace_media_file',
        'status' => ActionRequestStatus::Pending,
        'payload' => ['target' => replacementGuardEpisodeTarget(), 'service_connection_id' => 1],
    ]);

    expect(resolve(PendingReplacementGuard::class)->inFlightFor(replacementGuardEpisodeTarget()))->toBeTrue()
        ->and(resolve(PendingReplacementGuard::class)->inFlightFor(replacementGuardEpisodeTarget(['episode_numbers' => [2]])))->toBeFalse();
});
