<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Enums\BazarrServiceRole;
use App\Enums\SubtitleCaseStatus;
use App\Jobs\ExecuteActionRequest;
use App\Jobs\ReconcileSubtitleCase;
use App\Models\ActionRequest;
use App\Models\BazarrServiceLink;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Services\Actions\SharedMediaTargetLock;
use App\Services\Bazarr\BazarrActions;
use App\Services\Bazarr\BazarrCandidateFingerprint;
use App\Services\Bazarr\BazarrMediaFingerprint;
use App\Services\Bazarr\BazarrSubtitleFingerprint;
use App\Services\Bazarr\SubtitleCaseFingerprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const LINKED_TARGET_IDS = [
    'series_id' => 101,
    'episode_id' => 701,
    'episode_ids' => [701],
    'episode_file_id' => 501,
];

/**
 * Fakes for the live linked-target read BazarrActions performs before any write:
 * the Bazarr episode plus the Sonarr series/episode/file reads that rebuild the
 * case's file fingerprint. Tests that install their own fakes must spread these
 * in, otherwise the revalidation cannot see the target and aborts.
 *
 * @param  array<string, mixed>  $episodeFileOverrides
 * @return array<string, mixed>
 */
function linkedTargetLiveFakes(ServiceConnection $sonarr, array $episodeFileOverrides = []): array
{
    return [
        'bazarr.test/api/episodes?*' => Http::response(['data' => [[
            'sonarrSeriesId' => 101,
            'sonarrEpisodeId' => 701,
            'title' => 'Frieren S01E01',
            'subtitles' => [],
        ]]]),
        $sonarr->url.'/api/v3/series/101' => Http::response([
            'id' => 101,
            'title' => 'Frieren',
            'seriesType' => 'anime',
        ]),
        $sonarr->url.'/api/v3/episode?seriesId=101' => Http::response([
            ['id' => 701, 'seriesId' => 101, 'episodeFileId' => 501],
        ]),
        $sonarr->url.'/api/v3/episodefile/501' => Http::response([
            'id' => 501,
            'size' => 734_003_200,
            'dateAdded' => '2026-07-16T08:00:00Z',
            'sceneName' => 'Group.Frieren.S01E01',
            'path' => '/private/anime/Frieren S01E01.mkv',
            ...$episodeFileOverrides,
        ]),
    ];
}

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
        'target_ids' => LINKED_TARGET_IDS,
        'file_fingerprint' => hash('sha256', 'episode-file-v1'),
        'status' => SubtitleCaseStatus::DownloadRequested,
    ]);
    // Align the case with the target the shared live fakes describe, so the
    // pre-write revalidation agrees with the recorded fingerprint. Computed
    // directly rather than through a probe: Http::fake() appends stubs instead of
    // replacing them, so a fixture registered here would outrank the divergent
    // one a test installs later.
    $this->subtitleCase->forceFill([
        'target_ids' => LINKED_TARGET_IDS,
        'file_fingerprint' => resolve(SubtitleCaseFingerprint::class)->file([
            'service' => 'sonarr',
            'service_connection_id' => $this->sonarr->id,
            'file_ids' => [501],
            'media_ids' => [701],
            'size' => 734_003_200,
            'date_added' => '2026-07-16T08:00:00Z',
            'scene_name' => 'Group.Frieren.S01E01',
        ]),
    ])->save();
    $this->subtitleCase->refresh();

    $this->payload = [
        'title' => 'Download Swedish subtitles',
        'bazarr_connection_id' => $this->bazarr->id,
        'service_connection_id' => $this->sonarr->id,
        'subtitle_case_id' => $this->subtitleCase->id,
        'media_type' => 'episode',
        'target_ids' => LINKED_TARGET_IDS,
        'target_fingerprint' => $this->subtitleCase->file_fingerprint,
        'language' => 'swe',
        'forced' => false,
        'hearing_impaired' => false,
    ];
});

test('an approved remove_HI modification executes against Bazarr', function (): void {
    Http::fake([
        ...linkedTargetLiveFakes($this->sonarr),
        'bazarr.test/api/episodes?*' => Http::response(['data' => [[
            'sonarrSeriesId' => 101,
            'sonarrEpisodeId' => 701,
            'title' => 'Frieren S01E01',
            'subtitles' => [[
                'code3' => 'swe',
                'path' => '/private/anime/Frieren S01E01.sv.srt',
                'forced' => false,
                'hi' => true,
            ]],
        ]]]),
        'bazarr.test/api/subtitles' => Http::response('', 204),
    ]);
    $subtitleFingerprint = resolve(BazarrSubtitleFingerprint::class)->make([
        ...LINKED_TARGET_IDS,
        'media_type' => 'episode',
        'media_id' => 701,
        'path' => '/private/anime/Frieren S01E01.sv.srt',
        'language' => 'swe',
        'forced' => false,
        'hearing_impaired' => true,
        'display_name' => 'Frieren S01E01.sv.srt',
    ]);

    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Approved,
        'type' => 'bazarr_modify_subtitle',
        'payload' => [
            ...array_diff_key($this->payload, array_flip(['language', 'forced', 'hearing_impaired'])),
            'subtitle_fingerprint' => $subtitleFingerprint,
            'tool_action' => 'remove_HI',
        ],
    ]);

    resolve(BazarrActions::class)->execute($request);

    // The action name Bazarr publishes is case-sensitive, and it is the value the
    // drawer submits — validation must not reject it after approval.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && $request->url() === 'http://bazarr.test/api/subtitles'
        && $request->data()['action'] === 'remove_HI');
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

test('aborts a linked action when the live installed file no longer matches the case', function (): void {
    // The case row is an observation from probe time. If the episode file is
    // replaced while the approval is pending and nothing has refreshed that row,
    // the payload still agrees with it — so the live target has to be checked
    // before a write, exactly as the unlinked path already does.
    // The episode file was replaced upstream after approval; the case row still
    // carries the fingerprint the payload was approved against.
    Http::fake([
        ...linkedTargetLiveFakes($this->sonarr, [
            'sceneName' => 'Other.Group.Frieren.S01E01.REPACK',
            'size' => 999_000_000,
        ]),
        'bazarr.test/api/episodes/subtitles' => Http::response([], 204),
    ]);

    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Approved,
        'type' => 'bazarr_download_best',
        'payload' => $this->payload,
    ]);

    expect(fn (): array => resolve(BazarrActions::class)->execute($request))
        ->toThrow(InvalidArgumentException::class, 'changed after approval');

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH');
});

test('aborts an exact download when the approved candidate disappeared', function (): void {
    Http::fake([
        ...linkedTargetLiveFakes($this->sonarr),
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

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), '/api/providers/episodes'));
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

test('does not execute the same approved action twice', function (): void {
    Http::fake([
        ...linkedTargetLiveFakes($this->sonarr),
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
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && $request->url() === 'http://bazarr.test/api/episodes/subtitles');
    expect(Http::recorded()->filter(
        fn (array $record): bool => $record[0]->method() === 'PATCH',
    ))->toHaveCount(1);
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

test('the shared installed-file lease outlives the executor that holds it', function (): void {
    $timeouts = array_map(
        static fn (ReflectionAttribute $reflectionAttribute): int => $reflectionAttribute->newInstance()->timeout,
        new ReflectionClass(ExecuteActionRequest::class)->getAttributes(Timeout::class),
    );

    // A cache lock expires on its own schedule, so the lease has to cover the whole
    // job. Anything shorter lets a second worker acquire the key and write to the
    // same installed file while the first is still working.
    expect($timeouts)->toHaveCount(1)
        ->and(SharedMediaTargetLock::TTL_SECONDS)->toBeGreaterThan($timeouts[0])
        // The worker's own ceiling (docker/production/entrypoint.sh --timeout=300)
        // must stay inside the lease too.
        ->and(SharedMediaTargetLock::TTL_SECONDS)->toBeGreaterThan(300);
});

test('rejects execution while another subtitle operation holds the shared installed-file lock', function (): void {
    // Another Bazarr subtitle executor has claimed the same installed episode
    // file (managing Sonarr connection + episode id), so this subtitle operation
    // must not run concurrently against it. Media replacement deliberately takes
    // SharedMediaTargetLock, ensuring mutual exclusion with Bazarr subtitle
    // operations on the same installed file (see SharedMediaTargetLock's docblock).
    $lock = Cache::lock(
        SharedMediaTargetLock::key($this->sonarr->id, 'episode', 701),
        120,
    );
    expect($lock->get())->toBeTrue();

    try {
        $request = ActionRequest::factory()->create([
            'type' => 'bazarr_download_best',
            'payload' => $this->payload,
        ]);

        expect(fn (): array => resolve(BazarrActions::class)->execute($request))
            ->toThrow(RuntimeException::class, 'locked by another operation');
    } finally {
        $lock->release();
    }

    // The Bazarr-specific lock is taken before the shared one, so losing the
    // shared lock must not leave it held for its full TTL and turn a brief
    // collision into two minutes of rejected subtitle actions.
    $bazarrActionLock = Cache::lock(
        sprintf('bazarr-action:%d:%s', $this->bazarr->id, $this->payload['target_fingerprint']),
        120,
    );

    expect($bazarrActionLock->get())->toBeTrue();

    $bazarrActionLock->release();

    Http::assertNothingSent();
});

test('executes one successful write and returns a bounded result', function (): void {
    Http::fake([
        ...linkedTargetLiveFakes($this->sonarr),
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

    expect(Http::recorded()->filter(
        fn (array $record): bool => $record[0]->method() === 'PATCH',
    ))->toHaveCount(1);
});

test('keeps a definite upstream 4xx as a permanent rejection', function (): void {
    Http::fake([
        ...linkedTargetLiveFakes($this->sonarr),
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
    expect(Http::recorded()->filter(
        fn (array $record): bool => $record[0]->method() === 'PATCH',
    ))->toHaveCount(1);
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
    Http::fake([
        ...linkedTargetLiveFakes($this->sonarr),
        '*' => fn (Request $request) => $request->method() === 'GET'
            ? Http::response(['data' => [$candidate]])
            : Http::response(['message' => 'unavailable'], 503),
    ]);
    Queue::fake();
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
        // The uncertain outcome schedules targeted reconciliation for the linked
        // case instead of escalating it to needs_review immediately.
        ->and($this->subtitleCase->fresh()->status)->toBe(SubtitleCaseStatus::DownloadRequested);

    Queue::assertPushed(
        ReconcileSubtitleCase::class,
        fn (ReconcileSubtitleCase $reconcileSubtitleCase): bool => $reconcileSubtitleCase->subtitleCaseId === $this->subtitleCase->id,
    );

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://bazarr.test/api/providers/episodes');
    expect(Http::recorded()->filter(
        fn (array $record): bool => $record[0]->method() === 'POST',
    ))->toHaveCount(1);
});

test('marks connection loss after a write indeterminate without retrying the write', function (): void {
    $writeAttempts = 0;
    Http::fake([
        ...linkedTargetLiveFakes($this->sonarr),
        '*' => function (Request $request) use (&$writeAttempts) {
            if ($request->method() === 'GET') {
                return Http::response(['data' => [[
                    'sonarrSeriesId' => 101,
                    'sonarrEpisodeId' => 701,
                ]]]);
            }

            $writeAttempts++;

            throw new ConnectionException('connection lost after submit');
        },
    ]);
    Queue::fake();
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

    Queue::assertPushed(
        ReconcileSubtitleCase::class,
        fn (ReconcileSubtitleCase $reconcileSubtitleCase): bool => $reconcileSubtitleCase->subtitleCaseId === $this->subtitleCase->id,
    );
});

/**
 * An unlinked payload (no `subtitle_case_id`) is the path that escalates the
 * correlated case straight to needs_review, so the reason it stores is the one
 * an operator reads.
 *
 * @return array{0: ActionRequest, 1: array<string, mixed>}
 */
function unlinkedIndeterminateRequest(SubtitleCase $subtitleCase, array $payload): array
{
    $item = [
        'sonarrSeriesId' => 101,
        'sonarrEpisodeId' => 701,
        'sceneName' => 'Frieren.S01E01.1080p.WEB',
    ];
    $fingerprint = new BazarrMediaFingerprint()->make('episode', $item);
    $subtitleCase->update(['file_fingerprint' => $fingerprint]);

    $actionRequest = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Approved,
        'type' => 'bazarr_download_best',
        'payload' => [
            ...array_diff_key($payload, array_flip(['subtitle_case_id'])),
            'target_fingerprint' => $fingerprint,
        ],
    ]);
    $subtitleCase->update(['download_action_request_id' => $actionRequest->id]);

    return [$actionRequest, $item];
}

test('an indeterminate server error never persists raw upstream text on the case', function (): void {
    [$request, $item] = unlinkedIndeterminateRequest($this->subtitleCase, $this->payload);
    Http::fake(fn (Request $request) => $request->method() === 'GET'
        ? Http::response(['data' => [$item]])
        : Http::response(['error' => 'failed writing /mnt/private/anime/Frieren.S01E01.srt'], 500));
    Queue::fake();

    new ExecuteActionRequest($request)->handle();

    $failureReason = (string) $this->subtitleCase->fresh()->failure_reason;

    expect($this->subtitleCase->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview)
        ->and($failureReason)->not->toBe('')
        ->and($failureReason)->not->toContain('/mnt/private')
        ->and($failureReason)->not->toContain('Frieren.S01E01.srt');
});

test('an indeterminate connection loss never persists credentials from the upstream message', function (): void {
    [$request, $item] = unlinkedIndeterminateRequest($this->subtitleCase, $this->payload);
    Http::fake(function (Request $httpRequest) use ($item) {
        if ($httpRequest->method() === 'GET') {
            return Http::response(['data' => [$item]]);
        }

        throw new ConnectionException(
            'cURL error 7: Failed to connect to bazarr.test port 80 for http://bazarr.test/api/providers/episodes?apikey=bazarr-secret',
        );
    });
    Queue::fake();

    new ExecuteActionRequest($request)->handle();

    $failureReason = (string) $this->subtitleCase->fresh()->failure_reason;

    expect($this->subtitleCase->fresh()->status)->toBe(SubtitleCaseStatus::NeedsReview)
        ->and($failureReason)->not->toBe('')
        ->and($failureReason)->not->toContain('bazarr-secret');
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
    Queue::fake();
    $request = ActionRequest::factory()->create([
        'status' => ActionRequestStatus::Approved,
        'type' => 'bazarr_download_best',
        'payload' => $this->payload,
    ]);

    new ExecuteActionRequest($request)->handle();

    expect($this->subtitleCase->fresh()->status)->toBe(SubtitleCaseStatus::Resolved);
    Queue::assertNotPushed(ReconcileSubtitleCase::class);
});
