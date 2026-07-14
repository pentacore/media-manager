<?php

declare(strict_types=1);

use App\Enums\MediaReplacementStatus;
use App\Enums\UserRole;
use App\Models\MediaReplacementAttempt;
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

test('the custom timeout threshold is respected', function (): void {
    $attempt = MediaReplacementAttempt::factory()->create([
        'status' => MediaReplacementStatus::Downloading,
        'started_at' => now()->subHours(3),
    ]);

    $this->artisan('media-replacement:reconcile', ['--hours' => 2])->assertSuccessful();

    expect($attempt->fresh()->status)->toBe(MediaReplacementStatus::NeedsAttention);
});
