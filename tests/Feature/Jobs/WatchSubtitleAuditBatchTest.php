<?php

declare(strict_types=1);

use App\Jobs\WatchSubtitleAuditBatch;
use App\Models\SubtitleAuditBatch;
use App\Models\SubtitleAuditEpisode;
use App\Models\User;
use App\Notifications\MediaReplacementStatusChanged;
use App\Services\MediaReplacement\SubtitleAuditBatchSettler;
use Illuminate\Queue\Attributes\Queue as QueueAttribute;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->admin = User::factory()->admin()->create();
});

test('the watchdog is a final default lane job that waits out the chat request', function (): void {
    $reflection = new ReflectionClass(WatchSubtitleAuditBatch::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->getAttributes(QueueAttribute::class))->toBe([])
        ->and(WatchSubtitleAuditBatch::DELAY_SECONDS)->toBe(180)
        ->and(WatchSubtitleAuditBatch::DELAY_SECONDS)->toBeGreaterThan((int) config('octane.max_execution_time'));
});

test('the watchdog notifies admins about a ready batch the chat turn never claimed', function (): void {
    $batch = SubtitleAuditBatch::factory()->ready()->create();
    SubtitleAuditEpisode::factory()->for($batch, 'batch')->queued()->create();

    dispatch_sync(new WatchSubtitleAuditBatch($batch->id));

    expect($batch->refresh()->handed_off_at)->not->toBeNull();
    Notification::assertSentToTimes($this->admin, MediaReplacementStatusChanged::class, 1);
});

test('the watchdog leaves a batch whose card was shown alone', function (): void {
    $batch = SubtitleAuditBatch::factory()->cardShown()->create();

    dispatch_sync(new WatchSubtitleAuditBatch($batch->id));

    expect($batch->refresh()->handed_off_at)->toBeNull();
    Notification::assertNothingSent();
});

test('the watchdog does nothing when the batch is gone', function (): void {
    $this->mock(SubtitleAuditBatchSettler::class)->shouldReceive('adopt')->never();

    dispatch_sync(new WatchSubtitleAuditBatch(999_999));
});
