<?php

declare(strict_types=1);

use App\Enums\SubtitleAuditBatchStatus;
use App\Models\SubtitleAuditBatch;
use App\Models\SubtitleAuditEpisode;
use App\Models\User;
use App\Notifications\MediaReplacementStatusChanged;
use App\Services\MediaReplacement\SubtitleAuditBatchSettler;
use App\Services\MediaReplacement\SubtitleAuditBatchWaiter;
use Illuminate\Support\Facades\DB;
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

test('a notification from a settle inside an outer transaction waits for the commit', function (): void {
    $subtitleAuditBatchSettler = resolve(SubtitleAuditBatchSettler::class);

    $committed = SubtitleAuditBatch::factory()->create(['handed_off_at' => now()]);
    SubtitleAuditEpisode::factory()->for($committed, 'batch')->queued()->create();

    DB::transaction(function () use ($subtitleAuditBatchSettler, $committed): void {
        $subtitleAuditBatchSettler->settle($committed);
        Notification::assertNothingSent();
    });

    Notification::assertSentToTimes($this->admin, MediaReplacementStatusChanged::class, 1);

    $rolledBack = SubtitleAuditBatch::factory()->create(['handed_off_at' => now()]);
    SubtitleAuditEpisode::factory()->for($rolledBack, 'batch')->queued()->create();

    try {
        DB::transaction(function () use ($subtitleAuditBatchSettler, $rolledBack): void {
            $subtitleAuditBatchSettler->settle($rolledBack);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    Notification::assertSentToTimes($this->admin, MediaReplacementStatusChanged::class, 1);
    expect($rolledBack->refresh()->status)->toBe(SubtitleAuditBatchStatus::Searching);
});

test('the card is claimed only for a ready batch nobody handed off', function (): void {
    $subtitleAuditBatchSettler = resolve(SubtitleAuditBatchSettler::class);

    $ready = SubtitleAuditBatch::factory()->ready()->create();
    expect($subtitleAuditBatchSettler->claimCard($ready))->toBeTrue()
        ->and($ready->refresh()->card_shown_at)->not->toBeNull()
        ->and($ready->handed_off_at)->toBeNull();

    $searching = SubtitleAuditBatch::factory()->create();
    expect($subtitleAuditBatchSettler->claimCard($searching))->toBeFalse()
        ->and($searching->refresh()->card_shown_at)->toBeNull();

    $handedOff = SubtitleAuditBatch::factory()->handedOff()->create();
    expect($subtitleAuditBatchSettler->claimCard($handedOff))->toBeFalse()
        ->and($handedOff->refresh()->card_shown_at)->toBeNull();
});

test('adopting a batch nobody claimed hands it off and notifies once when it is ready', function (): void {
    $subtitleAuditBatchSettler = resolve(SubtitleAuditBatchSettler::class);
    $batch = SubtitleAuditBatch::factory()->ready()->create();
    SubtitleAuditEpisode::factory()->for($batch, 'batch')->queued()->create();

    $subtitleAuditBatchSettler->adopt($batch);
    $subtitleAuditBatchSettler->adopt($batch);

    expect($batch->refresh()->handed_off_at)->not->toBeNull()
        ->and($batch->card_shown_at)->toBeNull()
        ->and($subtitleAuditBatchSettler->claimCard($batch))->toBeFalse();
    Notification::assertSentToTimes($this->admin, MediaReplacementStatusChanged::class, 1);
    Notification::assertSentTo($this->admin, MediaReplacementStatusChanged::class, fn (MediaReplacementStatusChanged $notification): bool => str_contains(
        (string) json_encode($notification->toArray($this->admin)),
        'subtitle_audit_batch='.$batch->id,
    ));
});

test('adopting a still searching batch hands it off so its completion notifies', function (): void {
    $subtitleAuditBatchSettler = resolve(SubtitleAuditBatchSettler::class);
    $batch = SubtitleAuditBatch::factory()->create();
    $episode = SubtitleAuditEpisode::factory()->for($batch, 'batch')->create();

    $subtitleAuditBatchSettler->adopt($batch);

    expect($batch->refresh()->handed_off_at)->not->toBeNull();
    Notification::assertNothingSent();

    $episode->update(['outcome' => 'queued']);
    $subtitleAuditBatchSettler->settle($batch);

    Notification::assertSentToTimes($this->admin, MediaReplacementStatusChanged::class, 1);
});

test('adopting a batch whose card was shown or that was handed off does nothing', function (): void {
    $subtitleAuditBatchSettler = resolve(SubtitleAuditBatchSettler::class);
    $cardShown = SubtitleAuditBatch::factory()->cardShown()->create();
    $handedOffAt = now()->subMinutes(5)->startOfSecond();
    $handedOff = SubtitleAuditBatch::factory()->handedOff()->create(['handed_off_at' => $handedOffAt]);

    $subtitleAuditBatchSettler->adopt($cardShown);
    $subtitleAuditBatchSettler->adopt($handedOff);

    expect($cardShown->refresh()->handed_off_at)->toBeNull()
        ->and($handedOff->refresh()->handed_off_at?->equalTo($handedOffAt))->toBeTrue();
    Notification::assertNothingSent();
});
