<?php

declare(strict_types=1);

use App\Enums\SubtitleAuditBatchStatus;
use App\Models\SubtitleAuditBatch;
use App\Models\SubtitleAuditEpisode;
use App\Models\User;
use App\Notifications\MediaReplacementStatusChanged;
use App\Services\MediaReplacement\SubtitleAuditBatchSettler;
use App\Services\MediaReplacement\SubtitleAuditBatchWaiter;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    Notification::fake();
    $this->admin = User::factory()->admin()->create();
});

test('the last settled episode makes the batch ready', function (): void {
    $batch = SubtitleAuditBatch::factory()->create();
    SubtitleAuditEpisode::factory()->for($batch, 'batch')->queued()->create();

    resolve(SubtitleAuditBatchSettler::class)->settle($batch);

    $batch->refresh();
    expect($batch->status)->toBe(SubtitleAuditBatchStatus::Ready)
        ->and($batch->completed_at)->not->toBeNull();
});

test('a batch with a searching episode stays searching', function (): void {
    $batch = SubtitleAuditBatch::factory()->create();
    SubtitleAuditEpisode::factory()->for($batch, 'batch')->queued()->create();
    SubtitleAuditEpisode::factory()->for($batch, 'batch')->create();

    resolve(SubtitleAuditBatchSettler::class)->settle($batch);

    expect($batch->refresh()->status)->toBe(SubtitleAuditBatchStatus::Searching)
        ->and($batch->completed_at)->toBeNull();
});

test('a handed off batch notifies admins once when it becomes ready', function (): void {
    $batch = SubtitleAuditBatch::factory()->create(['handed_off_at' => now()]);
    SubtitleAuditEpisode::factory()->count(2)->for($batch, 'batch')->queued()->create();
    SubtitleAuditEpisode::factory()->for($batch, 'batch')->manualPick()->create();

    $subtitleAuditBatchSettler = resolve(SubtitleAuditBatchSettler::class);
    $subtitleAuditBatchSettler->settle($batch);
    $subtitleAuditBatchSettler->settle($batch);

    Notification::assertSentToTimes($this->admin, MediaReplacementStatusChanged::class, 1);
    Notification::assertSentTo($this->admin, MediaReplacementStatusChanged::class, function (MediaReplacementStatusChanged $notification) use ($batch): bool {
        $payload = $notification->toArray($this->admin);

        return str_contains((string) json_encode($payload), '2 replacements ready to approve, 1 needs a manual pick.')
            && str_contains((string) json_encode($payload), 'subtitle_audit_batch='.$batch->id);
    });
});

test('a batch that was not handed off does not notify', function (): void {
    $batch = SubtitleAuditBatch::factory()->create();
    SubtitleAuditEpisode::factory()->for($batch, 'batch')->queued()->create();

    resolve(SubtitleAuditBatchSettler::class)->settle($batch);

    Notification::assertNothingSent();
});

test('hand-off loses to a batch that is already ready', function (): void {
    $batch = SubtitleAuditBatch::factory()->ready()->create();

    expect(resolve(SubtitleAuditBatchSettler::class)->handOff($batch))->toBeFalse()
        ->and($batch->refresh()->handed_off_at)->toBeNull();
});

test('hand-off then completion notifies', function (): void {
    $batch = SubtitleAuditBatch::factory()->create();
    $episode = SubtitleAuditEpisode::factory()->for($batch, 'batch')->create();
    $subtitleAuditBatchSettler = resolve(SubtitleAuditBatchSettler::class);

    expect($subtitleAuditBatchSettler->handOff($batch))->toBeTrue()
        ->and($batch->refresh()->handed_off_at)->not->toBeNull();
    Notification::assertNothingSent();

    $episode->update(['outcome' => 'failed']);
    $subtitleAuditBatchSettler->settle($batch);

    Notification::assertSentToTimes($this->admin, MediaReplacementStatusChanged::class, 1);
});

test('the summary joins the non-zero parts and falls back to nothing to do', function (): void {
    $subtitleAuditBatchSettler = resolve(SubtitleAuditBatchSettler::class);
    $empty = SubtitleAuditBatch::factory()->ready()->create();
    $full = SubtitleAuditBatch::factory()->ready()->create();
    SubtitleAuditEpisode::factory()->for($full, 'batch')->queued()->create();
    SubtitleAuditEpisode::factory()->for($full, 'batch')->nothingFound()->create();
    SubtitleAuditEpisode::factory()->for($full, 'batch')->failed()->create();
    SubtitleAuditEpisode::factory()->for($full, 'batch')->create(['outcome' => 'no_longer_missing']);

    expect($subtitleAuditBatchSettler->summary($empty))->toBe('Nothing to do.')
        ->and($subtitleAuditBatchSettler->summary($full))->toBe('1 replacement ready to approve, 1 had no usable release, 1 no longer missing subtitles, 1 failed.');
});

test('the waiter returns true as soon as the batch is ready and false at the deadline', function (): void {
    Sleep::fake();
    $subtitleAuditBatchWaiter = resolve(SubtitleAuditBatchWaiter::class);

    $batch = SubtitleAuditBatch::factory()->create();
    $sleeps = 0;
    Sleep::whenFakingSleep(function () use ($batch, &$sleeps): void {
        if (++$sleeps === 3) {
            $batch->newQuery()->whereKey($batch->id)->update(['status' => SubtitleAuditBatchStatus::Ready]);
        }
    });

    expect($subtitleAuditBatchWaiter->waitForReady($batch, 60))->toBeTrue()
        ->and($sleeps)->toBe(3);

    $stuck = SubtitleAuditBatch::factory()->create();
    $sleeps = 0;

    expect($subtitleAuditBatchWaiter->waitForReady($stuck, 5))->toBeFalse()
        ->and($sleeps)->toBe(5);
});
