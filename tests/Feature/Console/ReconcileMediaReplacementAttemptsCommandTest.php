<?php

declare(strict_types=1);

use App\Enums\MediaReplacementStatus;
use App\Enums\SubtitleCaseStatus;
use App\Enums\UserRole;
use App\Models\ActionRequest;
use App\Models\MediaReplacementAttempt;
use App\Models\SubtitleCase;
use App\Models\User;
use App\Notifications\MediaReplacementStatusChanged;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    User::factory()->create(['role' => UserRole::Admin]);
});

test('a fresh downloading attempt is left untouched', function (): void {
    $attempt = MediaReplacementAttempt::factory()->create([
        'status' => MediaReplacementStatus::Downloading,
        'started_at' => now()->subHour(),
    ]);

    $this->artisan('media-replacement:reconcile')->assertSuccessful();

    expect($attempt->fresh()->status)->toBe(MediaReplacementStatus::Downloading);
    Notification::assertNothingSent();
});

test('a stale downloading attempt is flagged needs_attention and notifies admins', function (): void {
    $attempt = MediaReplacementAttempt::factory()->create([
        'status' => MediaReplacementStatus::Downloading,
        'started_at' => now()->subHours(9),
    ]);

    $this->artisan('media-replacement:reconcile')->assertSuccessful();

    $fresh = $attempt->fresh();
    expect($fresh->status)->toBe(MediaReplacementStatus::NeedsAttention)
        ->and($fresh->failure_reason)->toBe('download_timeout')
        ->and($fresh->completed_at)->not->toBeNull();

    Notification::assertSentTimes(MediaReplacementStatusChanged::class, 1);
});

test('a downloading attempt with null started_at uses created_at as the age basis', function (): void {
    $attempt = MediaReplacementAttempt::factory()->create([
        'status' => MediaReplacementStatus::Downloading,
        'started_at' => null,
    ]);
    $attempt->forceFill(['created_at' => now()->subHours(12)])->save();

    $this->artisan('media-replacement:reconcile')->assertSuccessful();

    expect($attempt->fresh()->status)->toBe(MediaReplacementStatus::NeedsAttention);
});

test('terminal attempts are never touched', function (): void {
    $verified = MediaReplacementAttempt::factory()->create([
        'status' => MediaReplacementStatus::Verified,
        'started_at' => now()->subDays(3),
    ]);
    $failed = MediaReplacementAttempt::factory()->create([
        'status' => MediaReplacementStatus::Failed,
        'started_at' => now()->subDays(3),
    ]);

    $this->artisan('media-replacement:reconcile')->assertSuccessful();

    expect($verified->fresh()->status)->toBe(MediaReplacementStatus::Verified)
        ->and($failed->fresh()->status)->toBe(MediaReplacementStatus::Failed);
    Notification::assertNothingSent();
});

test('the sweep restores monitoring the executor suspended', function (): void {
    Http::fake(['*' => Http::response([], 200)]);

    $attempt = MediaReplacementAttempt::factory()->create([
        'status' => MediaReplacementStatus::Downloading,
        'started_at' => now()->subHours(9),
        'was_monitored' => true,
        'monitoring_suspended' => true,
        'target' => ['service' => 'sonarr', 'series_id' => 42, 'episode_ids' => [7, 8], 'episode_file_ids' => [501]],
    ]);

    $this->artisan('media-replacement:reconcile')->assertSuccessful();

    $fresh = $attempt->fresh();
    expect($fresh->status)->toBe(MediaReplacementStatus::NeedsAttention)
        ->and($fresh->monitoring_suspended)->toBeFalse();

    // The remonitor call actually reached the arr.
    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'episode/monitor'));

    Notification::assertSentTo(
        User::first(),
        MediaReplacementStatusChanged::class,
        fn (MediaReplacementStatusChanged $mediaReplacementStatusChanged): bool => ! str_contains($mediaReplacementStatusChanged->message, 'Monitoring could not be restored'),
    );
});

test('the sweep notification never claims deletion that did not happen', function (): void {
    // grab never confirmed, cleanup never ran -> nothing was deleted.
    MediaReplacementAttempt::factory()->create([
        'status' => MediaReplacementStatus::Downloading,
        'started_at' => now()->subHours(9),
        'grab_accepted_at' => null,
        'cleanup_completed_at' => null,
    ]);

    $this->artisan('media-replacement:reconcile')->assertSuccessful();

    Notification::assertSentTo(
        User::first(),
        MediaReplacementStatusChanged::class,
        fn (MediaReplacementStatusChanged $mediaReplacementStatusChanged): bool => str_contains($mediaReplacementStatusChanged->message, 'the old file was not removed'),
    );
});

test('a timed-out attempt moves a correlated subtitle case to needs_review', function (): void {
    $actionRequest = ActionRequest::factory()->create(['type' => 'replace_media_file']);
    $case = SubtitleCase::factory()->create([
        'status' => SubtitleCaseStatus::ReplacementRequested,
        'replacement_action_request_id' => $actionRequest->id,
    ]);
    $actionRequest->update(['payload' => [...($actionRequest->payload ?? []), 'subtitle_case_id' => $case->id]]);
    $attempt = MediaReplacementAttempt::factory()->create([
        'action_request_id' => $actionRequest->id,
        'status' => MediaReplacementStatus::Downloading,
        'started_at' => now()->subHours(9),
    ]);

    $this->artisan('media-replacement:reconcile')->assertSuccessful();

    expect($attempt->fresh()->status)->toBe(MediaReplacementStatus::NeedsAttention)
        ->and($case->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview);
});

test('the custom timeout threshold is respected', function (): void {
    $attempt = MediaReplacementAttempt::factory()->create([
        'status' => MediaReplacementStatus::Downloading,
        'started_at' => now()->subHours(3),
    ]);

    $this->artisan('media-replacement:reconcile', ['--hours' => 2])->assertSuccessful();

    expect($attempt->fresh()->status)->toBe(MediaReplacementStatus::NeedsAttention);
});
