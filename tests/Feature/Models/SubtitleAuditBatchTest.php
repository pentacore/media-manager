<?php

declare(strict_types=1);

use App\Enums\SubtitleAuditBatchStatus;
use App\Enums\SubtitleAuditEpisodeOutcome;
use App\Models\ActionRequest;
use App\Models\SubtitleAuditBatch;
use App\Models\SubtitleAuditEpisode;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

test('an audit batch owns its episodes and they cascade with it', function (): void {
    $batch = SubtitleAuditBatch::factory()->create();
    SubtitleAuditEpisode::factory()->count(2)->create(['subtitle_audit_batch_id' => $batch->id]);

    expect($batch->episodes)->toHaveCount(2)
        ->and($batch->status)->toBe(SubtitleAuditBatchStatus::Searching)
        ->and($batch->episodes->first()->outcome)->toBe(SubtitleAuditEpisodeOutcome::Searching)
        ->and($batch->episodes->first()->batch->is($batch))->toBeTrue();

    $batch->delete();

    expect(SubtitleAuditEpisode::query()->count())->toBe(0);
});

test('an episode label pads season and episode', function (): void {
    $episode = SubtitleAuditEpisode::factory()->make(['season_number' => 1, 'episode_number' => 5]);

    expect($episode->label())->toBe('S01E05');
});

test('batch factory states set the handoff timestamps', function (): void {
    expect(SubtitleAuditBatch::factory()->ready()->create()->status)->toBe(SubtitleAuditBatchStatus::Ready)
        ->and(SubtitleAuditBatch::factory()->handedOff()->create()->handed_off_at)->not->toBeNull();
});

test('deleting the user nulls the batch owner', function (): void {
    $user = User::factory()->create();
    $batch = SubtitleAuditBatch::factory()->create(['user_id' => $user->id]);

    $user->delete();

    expect($batch->refresh()->user_id)->toBeNull();
});

test('deleting the action request nulls the episode link', function (): void {
    $actionRequest = ActionRequest::factory()->create();
    $episode = SubtitleAuditEpisode::factory()->queued()->create(['action_request_id' => $actionRequest->id]);

    $actionRequest->delete();

    expect($episode->refresh()->action_request_id)->toBeNull();
});

test('a batch cannot hold the same episode twice', function (): void {
    $batch = SubtitleAuditBatch::factory()->create();
    SubtitleAuditEpisode::factory()->create(['subtitle_audit_batch_id' => $batch->id, 'episode_id' => 101]);

    expect(fn () => SubtitleAuditEpisode::factory()->create(['subtitle_audit_batch_id' => $batch->id, 'episode_id' => 101]))
        ->toThrow(UniqueConstraintViolationException::class);
});
