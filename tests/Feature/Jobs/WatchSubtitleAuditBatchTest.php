<?php

declare(strict_types=1);

use App\Jobs\WatchSubtitleAuditBatch;
use App\Models\SubtitleAuditBatch;
use App\Models\SubtitleAuditEpisode;
use App\Models\User;
use App\Notifications\MediaReplacementStatusChanged;
use App\Services\MediaReplacement\SubtitleAuditBatchSettler;
use App\Settings\AppSettings;
use App\Settings\MediaReplacementSettings;
use Illuminate\Queue\Attributes\Queue as QueueAttribute;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    $this->admin = User::factory()->admin()->create();
});

test('the watchdog is a final default lane job that waits out the chat request', function (): void {
    config()->set('octane.max_execution_time', 150);
    $reflection = new ReflectionClass(WatchSubtitleAuditBatch::class);
    $mediaReplacementSettings = resolve(MediaReplacementSettings::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->getAttributes(QueueAttribute::class))->toBe([])
        ->and($mediaReplacementSettings->subtitleAuditWatchdogDelaySeconds())->toBe(180)
        ->and($mediaReplacementSettings->subtitleAuditWatchdogDelaySeconds())->toBeGreaterThan((int) config('octane.max_execution_time'));
});

test('the watchdog delay outlasts the chat request even when configured inside it', function (int $configured, int $expected): void {
    config()->set('octane.max_execution_time', 150);
    resolve(AppSettings::class)->set('ai.media_replacement', [
        'subtitle_audit' => ['watchdog_delay_seconds' => $configured],
    ]);

    expect(resolve(MediaReplacementSettings::class)->subtitleAuditWatchdogDelaySeconds())->toBe($expected)
        ->toBeGreaterThan((int) config('octane.max_execution_time'));
})->with([
    'below the ceiling' => [60, 180],
    'at the ceiling' => [150, 180],
    'past the ceiling' => [151, 151],
]);

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
