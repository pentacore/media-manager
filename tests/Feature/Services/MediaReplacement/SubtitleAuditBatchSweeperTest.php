<?php

declare(strict_types=1);

use App\Enums\SubtitleAuditBatchStatus;
use App\Enums\SubtitleAuditEpisodeOutcome;
use App\Models\SubtitleAuditBatch;
use App\Models\SubtitleAuditEpisode;
use App\Models\User;
use App\Notifications\MediaReplacementStatusChanged;
use App\Services\MediaReplacement\SubtitleAuditBatchSweeper;
use App\Settings\MediaReplacementSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    User::factory()->admin()->create();
});

test('an expired batch fails its searching episodes and becomes ready', function (): void {
    $batch = SubtitleAuditBatch::factory()->create(['search_deadline_at' => now()->subMinutes(10)]);
    $searching = SubtitleAuditEpisode::factory()->count(2)->for($batch, 'batch')->create();
    $queued = SubtitleAuditEpisode::factory()->for($batch, 'batch')->queued()->create();

    $swept = resolve(SubtitleAuditBatchSweeper::class)->sweep();

    expect($swept)->toBe(1)
        ->and($batch->refresh()->status)->toBe(SubtitleAuditBatchStatus::Ready);

    foreach ($searching as $episode) {
        expect($episode->refresh()->outcome)->toBe(SubtitleAuditEpisodeOutcome::Failed)
            ->and($episode->failure_reason)->toBe('search_expired');
    }

    expect($queued->refresh()->outcome)->toBe(SubtitleAuditEpisodeOutcome::Queued)
        ->and($queued->failure_reason)->toBeNull();
});

test('a batch inside the grace period is left alone', function (): void {
    $batch = SubtitleAuditBatch::factory()->create(['search_deadline_at' => now()->subMinutes(3)]);
    $episode = SubtitleAuditEpisode::factory()->for($batch, 'batch')->create();

    expect(resolve(SubtitleAuditBatchSweeper::class)->sweep())->toBe(0)
        ->and($batch->refresh()->status)->toBe(SubtitleAuditBatchStatus::Searching)
        ->and($episode->refresh()->outcome)->toBe(SubtitleAuditEpisodeOutcome::Searching);
});

test('the grace period follows the setting', function (): void {
    resolve(MediaReplacementSettings::class)->setConfiguration([
        'subtitle_audit' => ['sweep_grace_minutes' => 15],
    ]);
    $inside = SubtitleAuditBatch::factory()->create(['search_deadline_at' => now()->subMinutes(10)]);
    $past = SubtitleAuditBatch::factory()->create(['search_deadline_at' => now()->subMinutes(20)]);

    expect(resolve(SubtitleAuditBatchSweeper::class)->sweep())->toBe(1)
        ->and($inside->refresh()->status)->toBe(SubtitleAuditBatchStatus::Searching)
        ->and($past->refresh()->status)->toBe(SubtitleAuditBatchStatus::Ready);
});

test('a ready batch is left alone', function (): void {
    $batch = SubtitleAuditBatch::factory()->ready()->create(['search_deadline_at' => now()->subHour()]);
    $episode = SubtitleAuditEpisode::factory()->for($batch, 'batch')->create();

    expect(resolve(SubtitleAuditBatchSweeper::class)->sweep())->toBe(0)
        ->and($episode->refresh()->outcome)->toBe(SubtitleAuditEpisodeOutcome::Searching);
});

test('an expired handed-off batch notifies admins once', function (): void {
    $batch = SubtitleAuditBatch::factory()->create([
        'search_deadline_at' => now()->subMinutes(10),
        'handed_off_at' => now()->subMinutes(20),
    ]);
    SubtitleAuditEpisode::factory()->for($batch, 'batch')->create();

    resolve(SubtitleAuditBatchSweeper::class)->sweep();
    resolve(SubtitleAuditBatchSweeper::class)->sweep();

    Notification::assertSentTimes(MediaReplacementStatusChanged::class, 1);
});

test('an expired batch nobody handed off or showed notifies admins once', function (): void {
    $batch = SubtitleAuditBatch::factory()->create(['search_deadline_at' => now()->subMinutes(10)]);
    SubtitleAuditEpisode::factory()->for($batch, 'batch')->create();

    resolve(SubtitleAuditBatchSweeper::class)->sweep();
    resolve(SubtitleAuditBatchSweeper::class)->sweep();

    Notification::assertSentTimes(MediaReplacementStatusChanged::class, 1);
    expect($batch->refresh()->handed_off_at)->not->toBeNull();
});

test('the sweep settles every expired batch, reading them a chunk at a time', function (): void {
    $batches = SubtitleAuditBatch::factory()->count(3)->create(['search_deadline_at' => now()->subMinutes(10)]);
    $batches->each(fn (SubtitleAuditBatch $batch) => SubtitleAuditEpisode::factory()->for($batch, 'batch')->create());

    DB::enableQueryLog();
    $swept = resolve(SubtitleAuditBatchSweeper::class)->sweep();
    $batchReads = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query): bool => str_starts_with($query, 'select * from "subtitle_audit_batches"'));

    expect($swept)->toBe(3)
        ->and($batchReads)->not->toBeEmpty()
        ->and($batchReads->every(fn (string $query): bool => str_contains($query, 'limit')))->toBeTrue();

    foreach ($batches as $batch) {
        expect($batch->refresh()->status)->toBe(SubtitleAuditBatchStatus::Ready);
    }
});
