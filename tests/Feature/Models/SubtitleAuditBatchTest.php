<?php

declare(strict_types=1);

use App\Enums\SubtitleAuditBatchStatus;
use App\Enums\SubtitleAuditEpisodeOutcome;
use App\Models\SubtitleAuditBatch;
use App\Models\SubtitleAuditEpisode;

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
