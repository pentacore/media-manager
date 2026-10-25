<?php

declare(strict_types=1);

use App\Enums\MediaReplacementScope;
use App\Enums\SubtitleAuditBatchStatus;
use App\Enums\SubtitleAuditEpisodeOutcome;
use App\Jobs\SearchSubtitleAuditEpisode;
use App\Jobs\WatchSubtitleAuditBatch;
use App\Models\ActionTypeConfig;
use App\Models\ServiceConnection;
use App\Models\SubtitleAuditBatch;
use App\Models\User;
use App\Services\MediaReplacement\SeriesSubtitleAudit;
use App\Services\MediaReplacement\SeriesSubtitleAuditEpisode;
use App\Services\MediaReplacement\SeriesSubtitleRepairer;
use App\Services\MediaReplacement\SeriesSubtitleRepairRefused;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->connection = ServiceConnection::factory()->sonarr()->create();

    ActionTypeConfig::factory()->create([
        'type' => 'replace_media_file',
        'requires_approval' => false,
        'is_enabled' => true,
    ]);
});

/**
 * @param  list<SeriesSubtitleAuditEpisode>  $episodes
 */
function repairAudit(ServiceConnection $serviceConnection, array $episodes, ?int $seasonNumber = 1): SeriesSubtitleAudit
{
    return new SeriesSubtitleAudit(
        serviceConnectionId: $serviceConnection->id,
        seriesId: 42,
        seriesTitle: 'Show',
        scope: MediaReplacementScope::Tv,
        seasonNumber: $seasonNumber,
        episodes: $episodes,
    );
}

/**
 * @return list<SeriesSubtitleAuditEpisode>
 */
function repairEpisodes(): array
{
    return [
        new SeriesSubtitleAuditEpisode(101, 1, 1, SeriesSubtitleAuditEpisode::STATUS_MISSING, ['eng']),
        new SeriesSubtitleAuditEpisode(102, 1, 2, SeriesSubtitleAuditEpisode::STATUS_OK),
        new SeriesSubtitleAuditEpisode(103, 1, 3, SeriesSubtitleAuditEpisode::STATUS_MISSING, ['eng', 'swe']),
        new SeriesSubtitleAuditEpisode(104, 1, 4, SeriesSubtitleAuditEpisode::STATUS_SKIPPED, [], 'no_file'),
    ];
}

function repairRefusalReason(Closure $start): ?string
{
    try {
        $start();
    } catch (SeriesSubtitleRepairRefused $seriesSubtitleRepairRefused) {
        return $seriesSubtitleRepairRefused->reason;
    }

    return null;
}

test('a repair creates one searching row per missing episode and queues a search for each', function (): void {
    $subtitleAuditBatch = resolve(SeriesSubtitleRepairer::class)->start(repairAudit($this->connection, repairEpisodes()), $this->user);

    expect($subtitleAuditBatch->status)->toBe(SubtitleAuditBatchStatus::Searching)
        ->and($subtitleAuditBatch->user_id)->toBe($this->user->id)
        ->and($subtitleAuditBatch->service_connection_id)->toBe($this->connection->id)
        ->and($subtitleAuditBatch->series_id)->toBe(42)
        ->and($subtitleAuditBatch->series_title)->toBe('Show')
        ->and($subtitleAuditBatch->season_number)->toBe(1);

    $rows = $subtitleAuditBatch->episodes()->orderBy('episode_number')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('episode_id')->all())->toBe([101, 103])
        ->and($rows->pluck('episode_number')->all())->toBe([1, 3])
        ->and($rows[1]->missing_languages)->toBe(['eng', 'swe'])
        ->and($rows->every(fn ($row): bool => $row->outcome === SubtitleAuditEpisodeOutcome::Searching))->toBeTrue();

    Queue::assertPushed(SearchSubtitleAuditEpisode::class, 2);

    foreach ($rows as $row) {
        Queue::assertPushed(
            SearchSubtitleAuditEpisode::class,
            fn (SearchSubtitleAuditEpisode $job): bool => $job->subtitleAuditEpisodeId === $row->id,
        );
    }
});

test('a repair is refused when nothing is missing', function (): void {
    $seriesSubtitleAudit = repairAudit($this->connection, [
        new SeriesSubtitleAuditEpisode(102, 1, 2, SeriesSubtitleAuditEpisode::STATUS_OK),
    ]);

    $reason = repairRefusalReason(fn (): SubtitleAuditBatch => resolve(SeriesSubtitleRepairer::class)->start($seriesSubtitleAudit, $this->user));

    expect($reason)->toBe('nothing_missing')
        ->and(SubtitleAuditBatch::query()->count())->toBe(0);
    Queue::assertNotPushed(SearchSubtitleAuditEpisode::class);
});

test('a repair is refused when replace_media_file is disabled', function (): void {
    ActionTypeConfig::query()->where('type', 'replace_media_file')->update(['is_enabled' => false]);

    $reason = repairRefusalReason(fn (): SubtitleAuditBatch => resolve(SeriesSubtitleRepairer::class)
        ->start(repairAudit($this->connection, repairEpisodes()), $this->user));

    expect($reason)->toBe('action_type_disabled')
        ->and(SubtitleAuditBatch::query()->count())->toBe(0);
    Queue::assertNotPushed(SearchSubtitleAuditEpisode::class);
});

test('a repair is refused when replace_media_file is not configured', function (): void {
    ActionTypeConfig::query()->where('type', 'replace_media_file')->delete();

    $reason = repairRefusalReason(fn (): SubtitleAuditBatch => resolve(SeriesSubtitleRepairer::class)
        ->start(repairAudit($this->connection, repairEpisodes()), $this->user));

    expect($reason)->toBe('action_type_disabled');
});

test('a repair is refused while a batch for the series is still searching', function (): void {
    // Created long ago, but a large batch's search window is still open.
    SubtitleAuditBatch::factory()->create([
        'service_connection_id' => $this->connection->id,
        'series_id' => 42,
        'created_at' => now()->subHours(2),
        'search_deadline_at' => now()->addMinutes(20),
    ]);

    $reason = repairRefusalReason(fn (): SubtitleAuditBatch => resolve(SeriesSubtitleRepairer::class)
        ->start(repairAudit($this->connection, repairEpisodes()), $this->user));

    expect($reason)->toBe('batch_in_progress')
        ->and(SubtitleAuditBatch::query()->count())->toBe(1);
    Queue::assertNotPushed(SearchSubtitleAuditEpisode::class);
});

test('a ready batch or another series does not block a new repair', function (): void {
    SubtitleAuditBatch::factory()->ready()->create([
        'service_connection_id' => $this->connection->id,
        'series_id' => 42,
    ]);
    SubtitleAuditBatch::factory()->create([
        'service_connection_id' => $this->connection->id,
        'series_id' => 43,
    ]);

    resolve(SeriesSubtitleRepairer::class)->start(repairAudit($this->connection, repairEpisodes()), $this->user);

    Queue::assertPushed(SearchSubtitleAuditEpisode::class, 2);
});

test('a searching batch past its search deadline does not block a new repair', function (): void {
    SubtitleAuditBatch::factory()->create([
        'service_connection_id' => $this->connection->id,
        'series_id' => 42,
        'created_at' => now()->subMinutes(31),
        'search_deadline_at' => now()->subMinute(),
    ]);

    $subtitleAuditBatch = resolve(SeriesSubtitleRepairer::class)->start(repairAudit($this->connection, repairEpisodes()), $this->user);

    expect(SubtitleAuditBatch::query()->count())->toBe(2)
        ->and($subtitleAuditBatch->episodes()->count())->toBe(2);
    Queue::assertPushed(SearchSubtitleAuditEpisode::class, 2);
});

test('a repair is refused when another repair of the series holds the lock past the wait', function (): void {
    Sleep::fake(syncWithCarbon: true);
    Cache::lock(sprintf('subtitle-audit-repair:%d:42', $this->connection->id), 10)->get();

    $reason = repairRefusalReason(fn (): SubtitleAuditBatch => resolve(SeriesSubtitleRepairer::class)
        ->start(repairAudit($this->connection, repairEpisodes()), $this->user));

    expect($reason)->toBe('batch_in_progress')
        ->and(SubtitleAuditBatch::query()->count())->toBe(0);
    Queue::assertNotPushed(SearchSubtitleAuditEpisode::class);
});

test('the search deadline scales with the missing episode count', function (int $missingCount, int $expectedMinutes): void {
    $this->freezeSecond();

    $episodes = array_map(
        static fn (int $number): SeriesSubtitleAuditEpisode => new SeriesSubtitleAuditEpisode(1000 + $number, 1, $number, SeriesSubtitleAuditEpisode::STATUS_MISSING, ['eng']),
        range(1, $missingCount),
    );

    $subtitleAuditBatch = resolve(SeriesSubtitleRepairer::class)->start(repairAudit($this->connection, $episodes), $this->user);

    expect($subtitleAuditBatch->fresh()->search_deadline_at->getTimestamp())->toBe(now()->addMinutes($expectedMinutes)->getTimestamp());
})->with([
    'a small batch gets the 30 minute floor' => [2, 30],
    'three minutes per episode' => [50, 150],
    'a huge batch is capped at 12 hours' => [1000, 720],
]);

test('every queued search retries until the batch deadline', function (): void {
    $this->freezeSecond();

    $subtitleAuditBatch = resolve(SeriesSubtitleRepairer::class)->start(repairAudit($this->connection, repairEpisodes()), $this->user);
    $deadline = $subtitleAuditBatch->fresh()->search_deadline_at;

    Queue::assertPushed(SearchSubtitleAuditEpisode::class, 2);
    Queue::assertPushed(
        SearchSubtitleAuditEpisode::class,
        fn (SearchSubtitleAuditEpisode $searchSubtitleAuditEpisode): bool => $searchSubtitleAuditEpisode->retryUntil()->getTimestamp() === $deadline->getTimestamp(),
    );
    Queue::assertNotPushed(
        SearchSubtitleAuditEpisode::class,
        fn (SearchSubtitleAuditEpisode $searchSubtitleAuditEpisode): bool => $searchSubtitleAuditEpisode->retryUntil()->getTimestamp() !== $deadline->getTimestamp(),
    );
});

test('a repair schedules the batch watchdog three minutes out', function (): void {
    $this->freezeSecond();

    $subtitleAuditBatch = resolve(SeriesSubtitleRepairer::class)->start(repairAudit($this->connection, repairEpisodes()), $this->user);

    Queue::assertPushed(WatchSubtitleAuditBatch::class, 1);
    Queue::assertPushed(
        WatchSubtitleAuditBatch::class,
        fn (WatchSubtitleAuditBatch $watchSubtitleAuditBatch): bool => $watchSubtitleAuditBatch->subtitleAuditBatchId === $subtitleAuditBatch->id
            && $watchSubtitleAuditBatch->delay instanceof DateTimeInterface
            && $watchSubtitleAuditBatch->delay->getTimestamp() === now()->addSeconds(180)->getTimestamp(),
    );
});

test('a refused repair schedules no watchdog', function (): void {
    repairRefusalReason(fn (): SubtitleAuditBatch => resolve(SeriesSubtitleRepairer::class)->start(repairAudit($this->connection, [
        new SeriesSubtitleAuditEpisode(102, 1, 2, SeriesSubtitleAuditEpisode::STATUS_OK),
    ]), $this->user));

    Queue::assertNotPushed(WatchSubtitleAuditBatch::class);
});
