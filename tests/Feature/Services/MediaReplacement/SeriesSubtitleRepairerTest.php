<?php

declare(strict_types=1);

use App\Enums\MediaReplacementScope;
use App\Enums\SubtitleAuditBatchStatus;
use App\Enums\SubtitleAuditEpisodeOutcome;
use App\Jobs\SearchSubtitleAuditEpisode;
use App\Models\ActionTypeConfig;
use App\Models\ServiceConnection;
use App\Models\SubtitleAuditBatch;
use App\Models\User;
use App\Services\MediaReplacement\SeriesSubtitleAudit;
use App\Services\MediaReplacement\SeriesSubtitleAuditEpisode;
use App\Services\MediaReplacement\SeriesSubtitleRepairer;
use App\Services\MediaReplacement\SeriesSubtitleRepairRefused;
use Illuminate\Support\Facades\Queue;

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
    SubtitleAuditBatch::factory()->create([
        'service_connection_id' => $this->connection->id,
        'series_id' => 42,
        'created_at' => now()->subMinutes(10),
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

test('a searching batch older than 30 minutes does not block a new repair', function (): void {
    SubtitleAuditBatch::factory()->create([
        'service_connection_id' => $this->connection->id,
        'series_id' => 42,
        'created_at' => now()->subMinutes(31),
    ]);

    $subtitleAuditBatch = resolve(SeriesSubtitleRepairer::class)->start(repairAudit($this->connection, repairEpisodes()), $this->user);

    expect(SubtitleAuditBatch::query()->count())->toBe(2)
        ->and($subtitleAuditBatch->episodes()->count())->toBe(2);
    Queue::assertPushed(SearchSubtitleAuditEpisode::class, 2);
});
