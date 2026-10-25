<?php

declare(strict_types=1);

use App\Enums\QueueLane;
use App\Enums\SubtitleAuditEpisodeOutcome;
use App\Jobs\Middleware\LimitSubtitleAuditSearchConcurrency;
use App\Jobs\SearchSubtitleAuditEpisode;
use App\Models\ActionTypeConfig;
use App\Models\ServiceConnection;
use App\Models\SubtitleAuditBatch;
use App\Models\SubtitleAuditEpisode;
use App\Services\MediaReplacement\SubtitleAuditBatchSettler;
use App\Services\MediaReplacement\SubtitleAuditEpisodeSearcher;
use App\Settings\MediaReplacementSettings;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Queue as QueueAttribute;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();

    ActionTypeConfig::factory()->create(['type' => 'replace_media_file', 'is_enabled' => true]);

    $this->connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
        'settings' => [
            'sonarr_root_folders' => [['root_folder_id' => 1, 'path' => '/tv', 'scope' => 'tv']],
        ],
    ]);

    resolve(MediaReplacementSettings::class)->setConfiguration([
        'global_languages' => ['English'],
        'scoped_languages' => ['anime' => null, 'tv' => null, 'movie' => null],
    ]);

    $this->batch = SubtitleAuditBatch::factory()->create([
        'service_connection_id' => $this->connection->id,
        'series_id' => 42,
    ]);
    $this->episode = SubtitleAuditEpisode::factory()->create([
        'subtitle_audit_batch_id' => $this->batch->id,
        'episode_id' => 101,
        'season_number' => 1,
        'episode_number' => 1,
    ]);
});

test('the job waits for a search slot on the maintenance lane and gives up at the batch deadline', function (): void {
    $deadline = now()->addMinutes(90);
    $job = new SearchSubtitleAuditEpisode($this->episode->id, $deadline);
    $reflection = new ReflectionClass($job);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->getAttributes(QueueAttribute::class)[0]->newInstance()->queue)->toBe(QueueLane::Maintenance->value)
        ->and($reflection->getAttributes(Timeout::class)[0]->newInstance()->timeout)->toBe(270)
        ->and(LimitSubtitleAuditSearchConcurrency::RELEASE_AFTER_SECONDS)->toBe(300)
        ->and(LimitSubtitleAuditSearchConcurrency::RELEASE_AFTER_SECONDS)->toBeGreaterThan($reflection->getAttributes(Timeout::class)[0]->newInstance()->timeout)
        ->and($reflection->getAttributes(Tries::class)[0]->newInstance()->tries)->toBe(0)
        ->and($reflection->getAttributes(MaxExceptions::class)[0]->newInstance()->maxExceptions)->toBe(2)
        ->and($reflection->getAttributes(Backoff::class)[0]->newInstance()->backoff)->toBe(30)
        ->and($job->middleware())->toHaveCount(1)
        ->and($job->middleware()[0])->toBeInstanceOf(LimitSubtitleAuditSearchConcurrency::class)
        ->and($job->retryUntil()->getTimestamp())->toBe($deadline->getTimestamp())
        ->and($job->serviceConnectionId())->toBe($this->connection->id);
});

test('a search that keeps throwing marks the episode failed and settles the batch', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response([
            'id' => 42, 'title' => 'Show', 'path' => '/tv/Show', 'monitored' => true, 'tags' => [], 'seriesType' => 'standard',
        ]),
        'sonarr.local:8989/api/v3/episodefile/501' => Http::response([
            'id' => 501, 'seriesId' => 42, 'mediaInfo' => ['subtitles' => 'Japanese'],
        ]),
        'sonarr.local:8989/api/v3/episode*' => Http::response([[
            'id' => 101, 'seriesId' => 42, 'seasonNumber' => 1, 'episodeNumber' => 1,
            'episodeFileId' => 501, 'monitored' => true, 'hasFile' => true,
        ]]),
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => []]),
        'sonarr.local:8989/api/v3/release*' => Http::response('boom', 500),
    ]);

    $this->mock(SubtitleAuditBatchSettler::class)
        ->shouldReceive('settle')
        ->once()
        ->withArgs(fn (SubtitleAuditBatch $batch): bool => $batch->id === $this->batch->id);

    $job = new SearchSubtitleAuditEpisode($this->episode->id, now()->addMinutes(30));

    expect(fn () => $job->handle(resolve(SubtitleAuditEpisodeSearcher::class)))->toThrow(RequestException::class)
        ->and($this->episode->fresh()->outcome)->toBe(SubtitleAuditEpisodeOutcome::Searching);

    $job->failed(new RuntimeException('Sonarr kept failing.'));

    $episode = $this->episode->fresh();

    expect($episode->outcome)->toBe(SubtitleAuditEpisodeOutcome::Failed)
        ->and($episode->failure_reason)->toBe('search_failed');
});

test('a failed job leaves an episode that already has an outcome alone', function (): void {
    $this->episode->update(['outcome' => SubtitleAuditEpisodeOutcome::NothingFound]);

    new SearchSubtitleAuditEpisode($this->episode->id, now()->addMinutes(30))->failed(new RuntimeException('late'));

    expect($this->episode->fresh()->outcome)->toBe(SubtitleAuditEpisodeOutcome::NothingFound)
        ->and($this->episode->fresh()->failure_reason)->toBeNull();
});

test('a job whose episode row is gone does nothing', function (): void {
    $this->mock(SubtitleAuditBatchSettler::class)->shouldReceive('settle')->never();
    $episodeId = $this->episode->id;
    $this->episode->delete();

    $job = new SearchSubtitleAuditEpisode($episodeId, now()->addMinutes(30));
    $job->handle(resolve(SubtitleAuditEpisodeSearcher::class));
    $job->failed(new RuntimeException('gone'));

    expect($job->serviceConnectionId())->toBeNull();
    Http::assertNothingSent();
});

test('the job is released when both search slots for the connection are taken', function (): void {
    $searchSubtitleAuditEpisode = new SearchSubtitleAuditEpisode($this->episode->id, now()->addMinutes(30))->withFakeQueueInteractions();
    $ran = false;
    $funnel = fn (Closure $inside): mixed => Cache::funnel(sprintf('subtitle-audit-search:%d', $this->connection->id))
        ->limit(2)
        ->releaseAfter(LimitSubtitleAuditSearchConcurrency::RELEASE_AFTER_SECONDS)
        ->block(0)
        ->then($inside);

    $funnel(fn (): mixed => $funnel(function () use ($searchSubtitleAuditEpisode, &$ran): void {
        new LimitSubtitleAuditSearchConcurrency()->handle($searchSubtitleAuditEpisode, function () use (&$ran): void {
            $ran = true;
        });
    }));

    expect($ran)->toBeFalse();
    $searchSubtitleAuditEpisode->assertReleased(delay: 10);
});

test('the job runs when a search slot for the connection is free', function (): void {
    $searchSubtitleAuditEpisode = new SearchSubtitleAuditEpisode($this->episode->id, now()->addMinutes(30))->withFakeQueueInteractions();
    $ran = false;

    Cache::funnel(sprintf('subtitle-audit-search:%d', $this->connection->id))
        ->limit(2)
        ->releaseAfter(LimitSubtitleAuditSearchConcurrency::RELEASE_AFTER_SECONDS)
        ->block(0)
        ->then(function () use ($searchSubtitleAuditEpisode, &$ran): void {
            new LimitSubtitleAuditSearchConcurrency()->handle($searchSubtitleAuditEpisode, function () use (&$ran): void {
                $ran = true;
            });
        });

    expect($ran)->toBeTrue();
    $searchSubtitleAuditEpisode->assertNotReleased();
});
