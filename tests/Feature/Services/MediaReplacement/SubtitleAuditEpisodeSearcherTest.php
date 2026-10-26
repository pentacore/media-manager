<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Enums\SubtitleAuditEpisodeOutcome;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\ServiceConnection;
use App\Models\SubtitleAuditBatch;
use App\Models\SubtitleAuditEpisode;
use App\Models\User;
use App\Services\MediaReplacement\SubtitleAuditBatchSettler;
use App\Services\MediaReplacement\SubtitleAuditEpisodeSearcher;
use App\Settings\MediaReplacementSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();

    // requires_approval is false, so a Pending request can only come from the
    // searcher forcing approval.
    ActionTypeConfig::factory()->create([
        'type' => 'replace_media_file',
        'requires_approval' => false,
        'is_enabled' => true,
    ]);

    $this->connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
        'settings' => [
            'sonarr_root_folders' => [['root_folder_id' => 1, 'path' => '/tv', 'scope' => 'tv']],
        ],
    ]);

    resolve(MediaReplacementSettings::class)->setConfiguration(searcherSettings());

    $this->user = User::factory()->create(['name' => 'Ada']);
    $this->batch = SubtitleAuditBatch::factory()->create([
        'user_id' => $this->user->id,
        'service_connection_id' => $this->connection->id,
        'series_id' => 42,
        'series_title' => 'Show',
        'season_number' => 1,
    ]);
    $this->episode = SubtitleAuditEpisode::factory()->create([
        'subtitle_audit_batch_id' => $this->batch->id,
        'episode_id' => 101,
        'season_number' => 1,
        'episode_number' => 1,
        'missing_languages' => ['eng'],
    ]);
});

/**
 * The import check's test settings: threshold 90, a guarantee rule on CR and a
 * strong_evidence (85, below the threshold) rule on WEB.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function searcherSettings(array $overrides = []): array
{
    return array_replace([
        'automatic_selection_enabled' => true,
        'automatic_selection_threshold' => 90,
        'global_languages' => ['English'],
        'scoped_languages' => ['anime' => null, 'tv' => null, 'movie' => null],
        'season_pack_policy' => 'allowed',
        'subtitle_check' => ['enabled' => true, 'max_attempts_per_target' => 1, 'cooldown_hours' => 24],
        'guidance' => [
            'anime' => ['notes' => '', 'rules' => []],
            'tv' => [
                'notes' => '',
                'rules' => [
                    [
                        'name' => 'CR', 'enabled' => true, 'strength' => 'guarantee', 'languages' => ['English'],
                        'conditions' => [['field' => 'title', 'value' => 'CR']],
                    ],
                    // strong_evidence scores 85, below the 90 threshold.
                    [
                        'name' => 'WEB', 'enabled' => true, 'strength' => 'strong_evidence', 'languages' => ['English'],
                        'conditions' => [['field' => 'title', 'value' => 'WEB']],
                    ],
                ],
            ],
            'movie' => ['notes' => '', 'rules' => []],
        ],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function searcherRelease(array $overrides = []): array
{
    return array_replace([
        'guid' => 'g1', 'indexerId' => 10, 'title' => 'Show.S01E01.CR',
        'episodeIds' => [101], 'downloadAllowed' => true, 'rejections' => [], 'fullSeason' => false,
        'customFormatScore' => 0, 'qualityWeight' => 100, 'seeders' => 5, 'ageMinutes' => 60,
        'downloadUrl' => 'http://sonarr.local/download/g1',
    ], $overrides);
}

/**
 * @param  list<array<string, mixed>>|null  $releases
 */
function fakeSearcherSonarr(string $subtitles = 'Japanese', ?array $releases = null, ?Closure $onReleaseSearch = null): void
{
    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response([
            'id' => 42, 'title' => 'Show', 'path' => '/tv/Show', 'monitored' => true, 'tags' => [], 'seriesType' => 'standard',
        ]),
        // episodefile must precede the episode wildcard, which also matches it.
        'sonarr.local:8989/api/v3/episodefile/501' => Http::response([
            'id' => 501, 'seriesId' => 42, 'quality' => ['quality' => ['name' => 'WEBDL-1080p']],
            'size' => 100, 'sceneName' => 'Show.S01E01.OLD', 'releaseGroup' => 'OLD',
            'mediaInfo' => ['subtitles' => $subtitles],
        ]),
        'sonarr.local:8989/api/v3/episode*' => Http::response([[
            'id' => 101, 'seriesId' => 42, 'seasonNumber' => 1, 'episodeNumber' => 1,
            'episodeFileId' => 501, 'monitored' => true, 'hasFile' => true, 'title' => 'Ep 1',
        ]]),
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => [[
            'id' => 9001, 'eventType' => 'downloadFolderImported', 'episodeId' => 101, 'episodeFileId' => 501,
        ]]]),
        'sonarr.local:8989/api/v3/release*' => function () use ($releases, $onReleaseSearch) {
            if ($onReleaseSearch instanceof Closure) {
                $onReleaseSearch();
            }

            return Http::response($releases ?? [searcherRelease()]);
        },
    ]);
}

test('a confident candidate is queued as a pending request tagged with the batch', function (): void {
    fakeSearcherSonarr();

    resolve(SubtitleAuditEpisodeSearcher::class)->search($this->episode);

    $actionRequest = ActionRequest::query()->where('type', 'replace_media_file')->sole();
    $episode = $this->episode->fresh();

    expect($actionRequest->status)->toBe(ActionRequestStatus::Pending)
        ->and($actionRequest->requires_approval)->toBeTrue()
        ->and($actionRequest->origin)->toBe('chat')
        ->and($actionRequest->payload['subtitle_audit_batch_id'])->toBe($this->batch->id)
        ->and($actionRequest->payload['auto_check_key'])->toBe(sprintf('sonarr:%d:42-101', $this->connection->id))
        ->and($actionRequest->payload['selection_mode'])->toBe('automatic')
        ->and($actionRequest->payload['detail'])->toBe('Series subtitle audit: the installed file is missing eng.')
        ->and($actionRequest->description)->toStartWith('Requested by a series subtitle audit in chat by Ada.')
        ->and($episode->outcome)->toBe(SubtitleAuditEpisodeOutcome::Queued)
        ->and($episode->action_request_id)->toBe($actionRequest->id)
        ->and($episode->release_title)->toBe('Show.S01E01.CR');
});

test('the batch is settled after the outcome is written', function (): void {
    fakeSearcherSonarr();

    $this->mock(SubtitleAuditBatchSettler::class)
        ->shouldReceive('settle')
        ->once()
        ->withArgs(fn (SubtitleAuditBatch $batch): bool => $batch->id === $this->batch->id
            && $this->episode->fresh()->outcome === SubtitleAuditEpisodeOutcome::Queued);

    resolve(SubtitleAuditEpisodeSearcher::class)->search($this->episode);
});

test('an unconfident search leaves the episode for a manual pick', function (): void {
    fakeSearcherSonarr(releases: [searcherRelease(['title' => 'Show.S01E01.WEB'])]);

    resolve(SubtitleAuditEpisodeSearcher::class)->search($this->episode);

    expect($this->episode->fresh()->outcome)->toBe(SubtitleAuditEpisodeOutcome::ManualPick)
        ->and(ActionRequest::query()->count())->toBe(0);
});

test('a season pack best candidate is a manual pick, not batched', function (): void {
    // The ranker drops season packs today whatever the policy, so the confident
    // CR pack never reaches the shortlist and the searcher's own season-pack
    // guard is defence in depth; the outcome must stay a manual pick either way.
    resolve(MediaReplacementSettings::class)->setConfiguration(searcherSettings(['season_pack_policy' => 'automatic_above_threshold']));
    fakeSearcherSonarr(releases: [
        searcherRelease(['guid' => 'pack', 'title' => 'Show.S01.CR', 'fullSeason' => true]),
        searcherRelease(['guid' => 'web', 'title' => 'Show.S01E01.WEB']),
    ]);

    resolve(SubtitleAuditEpisodeSearcher::class)->search($this->episode);

    expect($this->episode->fresh()->outcome)->toBe(SubtitleAuditEpisodeOutcome::ManualPick)
        ->and(ActionRequest::query()->count())->toBe(0);
});

test('no candidates is nothing found', function (): void {
    fakeSearcherSonarr(releases: []);

    resolve(SubtitleAuditEpisodeSearcher::class)->search($this->episode);

    expect($this->episode->fresh()->outcome)->toBe(SubtitleAuditEpisodeOutcome::NothingFound)
        ->and(ActionRequest::query()->count())->toBe(0);
});

test('an episode that gained its subtitles is no longer missing', function (): void {
    fakeSearcherSonarr(subtitles: 'English/Japanese');

    resolve(SubtitleAuditEpisodeSearcher::class)->search($this->episode);

    expect($this->episode->fresh()->outcome)->toBe(SubtitleAuditEpisodeOutcome::NoLongerMissing)
        ->and(ActionRequest::query()->count())->toBe(0);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/release'));
});

test('an episode that cannot be identified fails with the inspection reason', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response([
            'id' => 42, 'title' => 'Show', 'path' => '/tv/Show', 'monitored' => true, 'tags' => [], 'seriesType' => 'standard',
        ]),
        'sonarr.local:8989/api/v3/episode*' => Http::response([[
            'id' => 101, 'seriesId' => 42, 'seasonNumber' => 1, 'episodeNumber' => 1,
            'episodeFileId' => 0, 'monitored' => true, 'hasFile' => false,
        ]]),
    ]);

    resolve(SubtitleAuditEpisodeSearcher::class)->search($this->episode);

    $episode = $this->episode->fresh();

    expect($episode->outcome)->toBe(SubtitleAuditEpisodeOutcome::Failed)
        ->and($episode->failure_reason)->toBe('no_file');
});

test('a disabled action type between repair and search fails the episode', function (): void {
    fakeSearcherSonarr();
    ActionTypeConfig::query()->where('type', 'replace_media_file')->update(['is_enabled' => false]);

    resolve(SubtitleAuditEpisodeSearcher::class)->search($this->episode);

    $episode = $this->episode->fresh();

    expect($episode->outcome)->toBe(SubtitleAuditEpisodeOutcome::Failed)
        ->and($episode->failure_reason)->toBe('action_type_disabled');
});

test('an episode whose row is no longer searching is left alone', function (): void {
    fakeSearcherSonarr();
    $this->episode->update(['outcome' => SubtitleAuditEpisodeOutcome::ManualPick]);

    $this->mock(SubtitleAuditBatchSettler::class)->shouldReceive('settle')->never();

    resolve(SubtitleAuditEpisodeSearcher::class)->search($this->episode->fresh());

    expect($this->episode->fresh()->outcome)->toBe(SubtitleAuditEpisodeOutcome::ManualPick)
        ->and(ActionRequest::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('a search whose episode was swept meanwhile neither queues a request nor overwrites the outcome', function (): void {
    fakeSearcherSonarr(onReleaseSearch: fn (): int => SubtitleAuditEpisode::query()->whereKey($this->episode->id)->update([
        'outcome' => SubtitleAuditEpisodeOutcome::Failed,
        'failure_reason' => 'search_expired',
    ]));

    $this->mock(SubtitleAuditBatchSettler::class)->shouldReceive('settle')->never();

    resolve(SubtitleAuditEpisodeSearcher::class)->search($this->episode);

    $episode = $this->episode->fresh();

    expect(ActionRequest::query()->count())->toBe(0)
        ->and($episode->outcome)->toBe(SubtitleAuditEpisodeOutcome::Failed)
        ->and($episode->failure_reason)->toBe('search_expired')
        ->and($episode->action_request_id)->toBeNull();
});

test('an episode with a replacement already in flight fails instead of queueing a second one', function (): void {
    fakeSearcherSonarr();
    $inFlight = ActionRequest::factory()->create([
        'type' => 'replace_media_file',
        'status' => ActionRequestStatus::Pending,
        'payload' => ['target' => [
            'service_connection_id' => $this->connection->id,
            'series_id' => 42,
            'season_number' => 1,
            'episode_numbers' => [1],
        ]],
    ]);

    resolve(SubtitleAuditEpisodeSearcher::class)->search($this->episode);

    $episode = $this->episode->fresh();

    expect($episode->outcome)->toBe(SubtitleAuditEpisodeOutcome::Failed)
        ->and($episode->failure_reason)->toBe('replacement_in_flight')
        ->and($episode->missing_languages)->toBe(['eng'])
        ->and($episode->action_request_id)->toBeNull()
        ->and(ActionRequest::query()->pluck('id')->all())->toBe([$inFlight->id]);
});
