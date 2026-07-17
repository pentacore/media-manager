<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Enums\BazarrServiceRole;
use App\Enums\SubtitleCaseStatus;
use App\Jobs\ExecuteActionRequest;
use App\Models\ActionRequest;
use App\Models\BazarrServiceLink;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Services\Bazarr\BazarrActions;
use App\Services\Bazarr\BazarrCandidateFingerprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('b', 32)));
    config()->set('cache.default', 'array');
    Http::preventStrayRequests();

    $this->bazarr = ServiceConnection::factory()->bazarr()->create([
        'url' => 'http://bazarr.test',
        'api_key' => 'bazarr-secret',
    ]);
    $this->sonarr = ServiceConnection::factory()->sonarr()->create();
    BazarrServiceLink::factory()->create([
        'bazarr_connection_id' => $this->bazarr->id,
        'related_connection_id' => $this->sonarr->id,
        'role' => BazarrServiceRole::Sonarr,
    ]);
    $this->subtitleCase = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $this->bazarr->id,
        'service_connection_id' => $this->sonarr->id,
        'media_type' => 'episode',
        'target_ids' => ['series_id' => 101, 'episode_id' => 701],
        'file_fingerprint' => hash('sha256', 'episode-file-v1'),
        'status' => SubtitleCaseStatus::DownloadRequested,
    ]);
    $this->payload = [
        'title' => 'Download Swedish subtitles',
        'bazarr_connection_id' => $this->bazarr->id,
        'service_connection_id' => $this->sonarr->id,
        'subtitle_case_id' => $this->subtitleCase->id,
        'media_type' => 'episode',
        'target_ids' => ['series_id' => 101, 'episode_id' => 701],
        'target_fingerprint' => $this->subtitleCase->file_fingerprint,
        'language' => 'swe',
        'forced' => false,
        'hearing_impaired' => false,
    ];
});

test('rejects action types outside the Bazarr allowlist', function (): void {
    $request = ActionRequest::factory()->create([
        'type' => 'delete_series',
        'payload' => $this->payload,
    ]);

    expect(fn (): array => resolve(BazarrActions::class)->execute($request))
        ->toThrow(InvalidArgumentException::class, 'cannot execute');
});

test('rejects payload keys outside the strict action shape', function (): void {
    $request = ActionRequest::factory()->create([
        'type' => 'bazarr_download_best',
        'payload' => [...$this->payload, 'path' => '/browser/injected.srt'],
    ]);

    expect(fn (): array => resolve(BazarrActions::class)->execute($request))
        ->toThrow(InvalidArgumentException::class, 'unexpected fields');
});

test('aborts when the linked media file fingerprint changed after approval', function (): void {
    $this->subtitleCase->update(['file_fingerprint' => hash('sha256', 'episode-file-v2')]);
    $request = ActionRequest::factory()->create([
        'type' => 'bazarr_download_best',
        'payload' => $this->payload,
    ]);

    expect(fn (): array => resolve(BazarrActions::class)->execute($request))
        ->toThrow(InvalidArgumentException::class, 'changed after approval');

    Http::assertNothingSent();
});

test('aborts an exact download when the approved candidate disappeared', function (): void {
    Http::fake([
        'bazarr.test/api/episodes?*' => Http::response(['data' => [[
            'sonarrSeriesId' => 101,
            'sonarrEpisodeId' => 701,
            'path' => '/media/show/episode.mkv',
            'subtitles' => [],
        ]]]),
        'bazarr.test/api/providers/episodes*' => Http::response(['data' => []]),
    ]);
    $request = ActionRequest::factory()->create([
        'type' => 'bazarr_download_exact',
        'payload' => [
            ...array_diff_key($this->payload, array_flip(['language', 'forced', 'hearing_impaired'])),
            'candidate_fingerprint' => hash('sha256', 'missing-candidate'),
        ],
    ]);

    expect(fn (): array => resolve(BazarrActions::class)->execute($request))
        ->toThrow(InvalidArgumentException::class, 'no longer available');

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

test('does not execute the same approved action twice', function (): void {
    Http::fake([
        'bazarr.test/api/episodes?*' => Http::response(['data' => [[
            'sonarrSeriesId' => 101,
            'sonarrEpisodeId' => 701,
            'path' => '/media/show/episode.mkv',
            'subtitles' => [],
        ]]]),
        'bazarr.test/api/episodes/subtitles' => Http::response('', 204),
    ]);
    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Approved,
        'type' => 'bazarr_download_best',
        'payload' => $this->payload,
    ]);

    new ExecuteActionRequest($request)->handle();
    new ExecuteActionRequest($request->fresh())->handle();

    expect($request->fresh()->status)->toBe(ActionRequestStatus::Completed);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && $request->url() === 'http://bazarr.test/api/episodes/subtitles');
});

test('rejects execution while another worker owns the target lock', function (): void {
    $lock = Cache::lock(
        sprintf('bazarr-action:%d:%s', $this->bazarr->id, $this->payload['target_fingerprint']),
        120,
    );
    expect($lock->get())->toBeTrue();

    try {
        $request = ActionRequest::factory()->create([
            'type' => 'bazarr_download_best',
            'payload' => $this->payload,
        ]);

        expect(fn (): array => resolve(BazarrActions::class)->execute($request))
            ->toThrow(RuntimeException::class, 'already being modified');
    } finally {
        $lock->release();
    }

    Http::assertNothingSent();
});

test('executes one successful write and returns a bounded result', function (): void {
    Http::fake([
        'bazarr.test/api/episodes?*' => Http::response(['data' => [[
            'sonarrSeriesId' => 101,
            'sonarrEpisodeId' => 701,
            'path' => '/media/show/episode.mkv',
            'subtitles' => [],
        ]]]),
        'bazarr.test/api/episodes/subtitles' => Http::response('', 204),
    ]);
    $request = ActionRequest::factory()->create([
        'type' => 'bazarr_download_best',
        'payload' => $this->payload,
    ]);

    expect(resolve(BazarrActions::class)->execute($request))->toBe([
        'operation' => 'bazarr_download_best',
        'media_type' => 'episode',
        'media_id' => 701,
    ]);

    Http::assertSentCount(2);
});

test('keeps a definite upstream 4xx as a permanent rejection', function (): void {
    Http::fake([
        'bazarr.test/api/episodes?*' => Http::response(['data' => [[
            'sonarrSeriesId' => 101,
            'sonarrEpisodeId' => 701,
            'path' => '/media/show/episode.mkv',
            'subtitles' => [],
        ]]]),
        'bazarr.test/api/episodes/subtitles' => Http::response(['message' => 'invalid'], 409),
    ]);
    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Approved,
        'type' => 'bazarr_download_best',
        'payload' => $this->payload,
    ]);

    new ExecuteActionRequest($request)->handle();

    expect($request->fresh()->status)->toBe(ActionRequestStatus::Failed)
        ->and($request->fresh()->result)->toMatchArray([
            'reason' => 'execution_failed',
            'indeterminate' => false,
        ]);
    Http::assertSentCount(2);
});

test('marks a 5xx exact download indeterminate without issuing a second POST or Laravel retry', function (): void {
    $candidate = [
        'provider' => 'AnimeTosho',
        'subtitle' => 'private-provider-id',
        'language' => 'swe',
        'forced' => false,
        'hearing_impaired' => false,
        'score' => 98,
        'release_info' => ['Example.Release'],
    ];
    $candidateFingerprint = new BazarrCandidateFingerprint()->make([
        'media_type' => 'episode',
        'media_id' => 701,
        ...$candidate,
    ]);
    Http::fake(fn (Request $request) => $request->method() === 'GET'
        ? Http::response(['data' => [$candidate]])
        : Http::response(['message' => 'unavailable'], 503));
    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Approved,
        'type' => 'bazarr_download_exact',
        'payload' => [
            ...array_diff_key($this->payload, array_flip(['language', 'forced', 'hearing_impaired'])),
            'candidate_fingerprint' => $candidateFingerprint,
        ],
    ]);

    new ExecuteActionRequest($request)->handle();

    expect($request->fresh()->status)->toBe(ActionRequestStatus::Failed)
        ->and($request->fresh()->result)->toMatchArray([
            'reason' => 'needs_reconciliation',
            'indeterminate' => true,
        ])
        ->and($this->subtitleCase->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://bazarr.test/api/providers/episodes');
    expect(Http::recorded()->filter(
        fn (array $record): bool => $record[0]->method() === 'POST',
    ))->toHaveCount(1);
});

test('marks connection loss after a write indeterminate without retrying the write', function (): void {
    $writeAttempts = 0;
    Http::fake(function (Request $request) use (&$writeAttempts) {
        if ($request->method() === 'GET') {
            return Http::response(['data' => [[
                'sonarrSeriesId' => 101,
                'sonarrEpisodeId' => 701,
            ]]]);
        }

        $writeAttempts++;

        throw new ConnectionException('connection lost after submit');
    });
    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Approved,
        'type' => 'bazarr_download_best',
        'payload' => $this->payload,
    ]);

    new ExecuteActionRequest($request)->handle();

    expect($request->fresh()->result)->toMatchArray([
        'reason' => 'needs_reconciliation',
        'indeterminate' => true,
    ])->and($writeAttempts)->toBe(1);
});

test('does not overwrite a terminal subtitle case after an indeterminate outcome', function (): void {
    $this->subtitleCase->update(['status' => SubtitleCaseStatus::Resolved]);
    Http::fake([
        'bazarr.test/api/episodes?*' => Http::response(['data' => [[
            'sonarrSeriesId' => 101,
            'sonarrEpisodeId' => 701,
        ]]]),
        'bazarr.test/api/episodes/subtitles' => Http::response([], 503),
    ]);
    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Approved,
        'type' => 'bazarr_download_best',
        'payload' => $this->payload,
    ]);

    new ExecuteActionRequest($request)->handle();

    expect($this->subtitleCase->fresh()->status)->toBe(SubtitleCaseStatus::Resolved);
});
