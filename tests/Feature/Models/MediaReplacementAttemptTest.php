<?php

declare(strict_types=1);

use App\Enums\MediaReplacementStatus;
use App\Models\ActionRequest;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

test('it persists and casts a replacement attempt', function (): void {
    $attempt = MediaReplacementAttempt::factory()->create([
        'status' => MediaReplacementStatus::Requested,
        'target' => ['service' => 'sonarr', 'episode_file_ids' => [501]],
        'candidate' => ['title' => 'Show S01E01 CR'],
        'required_languages' => ['eng'],
    ]);

    $fresh = $attempt->fresh();

    expect($fresh->status)->toBe(MediaReplacementStatus::Requested)
        ->and($fresh->target['episode_file_ids'])->toBe([501])
        ->and($fresh->candidate['title'])->toBe('Show S01E01 CR')
        ->and($fresh->required_languages)->toBe(['eng'])
        ->and($fresh->actionRequest)->toBeInstanceOf(ActionRequest::class)
        ->and($fresh->serviceConnection)->toBeInstanceOf(ServiceConnection::class);
});

test('it casts timestamps to immutable datetimes', function (): void {
    $attempt = MediaReplacementAttempt::factory()->create([
        'started_at' => now(),
        'completed_at' => null,
    ]);

    expect($attempt->started_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($attempt->completed_at)->toBeNull();
});

test('it enforces one attempt per action request', function (): void {
    $actionRequest = ActionRequest::factory()->create(['type' => 'replace_media_file']);

    MediaReplacementAttempt::factory()->create(['action_request_id' => $actionRequest->id]);

    expect(fn (): MediaReplacementAttempt => MediaReplacementAttempt::factory()->create(['action_request_id' => $actionRequest->id]))
        ->toThrow(QueryException::class);
});

test('it records who acknowledged an attempt and counts open attention rows', function (): void {
    $admin = User::factory()->admin()->create();
    MediaReplacementAttempt::factory()->needsAttention()->create();
    $acknowledged = MediaReplacementAttempt::factory()->needsAttention()->acknowledged($admin)->create();
    MediaReplacementAttempt::factory()->verified()->create();

    $fresh = $acknowledged->fresh();

    expect($fresh->acknowledged_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($fresh->acknowledgedBy->is($admin))->toBeTrue()
        ->and(MediaReplacementAttempt::unacknowledgedAttentionCount())->toBe(1);
});

test('an action request exposes its replacement attempt', function (): void {
    $attempt = MediaReplacementAttempt::factory()->downloading()->create();

    expect($attempt->actionRequest->mediaReplacementAttempt->is($attempt))->toBeTrue()
        ->and(ActionRequest::factory()->create()->mediaReplacementAttempt)->toBeNull();
});
