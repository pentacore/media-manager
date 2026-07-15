<?php

declare(strict_types=1);

use App\Enums\MediaReplacementStatus;
use App\Models\ActionRequest;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Services\MediaReplacement\MediaReplacementActions;
use App\Services\MediaReplacement\MediaReplacementTracker;
use App\Services\MediaReplacement\ReleaseFingerprint;
use App\Settings\MediaReplacementSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();

    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
    ]);

    resolve(MediaReplacementSettings::class)->setConfiguration([
        'automatic_selection_enabled' => false,
        'automatic_selection_threshold' => 90,
        'global_languages' => ['English'],
        'scoped_languages' => ['anime' => null, 'tv' => null, 'movie' => null],
        'season_pack_policy' => 'approval_required',
        'guidance' => [
            'anime' => [
                'notes' => '',
                'rules' => [[
                    'name' => 'CR', 'enabled' => true, 'strength' => 'guarantee', 'languages' => ['English'],
                    'conditions' => [['field' => 'title', 'value' => 'CR']],
                ]],
            ],
            'tv' => ['notes' => '', 'rules' => []],
            'movie' => ['notes' => '', 'rules' => []],
        ],
    ]);
});

/**
 * @return array<string, mixed>
 */
function sonarrReplacementRelease(): array
{
    return [
        'guid' => 'g1', 'indexerId' => 10, 'title' => 'Trusted.Anime.S01E01.CR',
        'episodeIds' => [101], 'downloadAllowed' => true, 'rejections' => [], 'fullSeason' => false,
        'customFormatScore' => 0, 'qualityWeight' => 100, 'seeders' => 5, 'ageMinutes' => 60,
        'downloadUrl' => 'http://sonarr.local/download/g1',
    ];
}

/**
 * Method-aware fake so a GET and DELETE on the same /episodefile/{id} URL can
 * return different statuses.
 *
 * @param  array{grabOk?: bool, grabConnection?: bool, grabStatus?: int, deleteOk?: bool, monitorOk?: bool, monitored?: bool, currentFileId?: int, releases?: list<array<string, mixed>>, onDelete?: callable}  $opts
 */
function fakeExecutor(array $opts = []): void
{
    $grabOk = $opts['grabOk'] ?? true;
    $grabConnection = $opts['grabConnection'] ?? false;
    $grabStatus = $opts['grabStatus'] ?? ($grabOk ? 201 : 500);
    $deleteOk = $opts['deleteOk'] ?? true;
    $deleteStatus = $opts['deleteStatus'] ?? ($deleteOk ? 200 : 500);
    $monitorOk = $opts['monitorOk'] ?? true;
    $monitored = $opts['monitored'] ?? true;
    $currentFileId = $opts['currentFileId'] ?? 501;
    $releases = $opts['releases'] ?? [sonarrReplacementRelease()];
    $onDelete = $opts['onDelete'] ?? null;

    Http::fake(function (Request $request) use ($grabConnection, $grabStatus, $deleteStatus, $monitorOk, $monitored, $currentFileId, $releases, $onDelete) {
        $method = $request->method();
        $url = $request->url();

        if ($method === 'POST' && str_contains($url, '/api/v3/release') && $grabConnection) {
            throw new ConnectionException('Connection timed out');
        }

        if ($method === 'DELETE' && str_contains($url, '/api/v3/episodefile/') && $onDelete !== null) {
            $onDelete();
        }

        return match (true) {
            $method === 'POST' && str_contains($url, '/api/v3/release') => Http::response([], $grabStatus),
            $method === 'GET' && str_contains($url, '/api/v3/release') => Http::response($releases),
            $method === 'PUT' && str_contains($url, '/api/v3/episode/monitor') => Http::response([], $monitorOk ? 200 : 500),
            $method === 'DELETE' && str_contains($url, '/api/v3/episodefile/') => Http::response([], $deleteStatus),
            str_contains($url, '/api/v3/series/42') => Http::response(['id' => 42, 'title' => 'Trusted Anime', 'seriesType' => 'anime']),
            str_contains($url, '/api/v3/episode?') => Http::response([
                ['id' => 101, 'seasonNumber' => 1, 'episodeNumber' => 1, 'episodeFileId' => $currentFileId, 'monitored' => $monitored],
            ]),
            str_contains($url, '/api/v3/episodefile/') => Http::response([
                'id' => $currentFileId,
                'sceneName' => $currentFileId === 501 ? 'Trusted.Anime.S01E01.OLD' : 'DIFFERENT',
                'mediaInfo' => ['subtitles' => 'Japanese'],
            ]),
            str_contains($url, '/api/v3/history/failed/') => Http::response([], 200),
            str_contains($url, '/api/v3/history') => Http::response(['records' => [
                ['id' => 999, 'eventType' => 'grabbed', 'episodeId' => 101],
            ]]),
            default => Http::response([], 200),
        };
    });
}

function replaceActionRequest(): ActionRequest
{
    $fingerprint = (new ReleaseFingerprint)->make('sonarr', sonarrReplacementRelease());

    return ActionRequest::factory()->create([
        'type' => 'replace_media_file',
        'source_service' => 'ai',
        'target_service' => 'sonarr',
        'payload' => [
            'service' => 'sonarr',
            'scope' => 'anime',
            'target' => [
                'service' => 'sonarr', 'scope' => 'anime', 'series_id' => 42,
                'season_number' => 1, 'episode_numbers' => [1], 'episode_ids' => [101],
                'episode_file_ids' => [501], 'installed_release' => 'Trusted.Anime.S01E01.OLD',
                'original_history_id' => 999,
            ],
            'candidate_fingerprint' => $fingerprint,
            'candidate' => ['fingerprint' => $fingerprint, 'title' => 'Trusted.Anime.S01E01.CR', 'confidence' => 98],
            'required_languages' => ['eng'],
            'selection_mode' => 'manual',
            'original_history_id' => 999,
        ],
    ]);
}

test('grabs the replacement before deleting the reviewed file', function (): void {
    fakeExecutor();

    $result = resolve(MediaReplacementActions::class)->execute(replaceActionRequest());

    expect($result['replacement_initiated'])->toBeTrue()
        ->and($result['status'])->toBe('downloading')
        ->and($result['deleted_files'])->toBe(1);

    $requests = Http::recorded()->map(fn (array $pair): string => $pair[0]->method().' '.$pair[0]->url())->values();
    $grabIndex = $requests->search(fn (string $value): bool => $value === 'POST http://sonarr.local:8989/api/v3/release');
    $deleteIndex = $requests->search(fn (string $value): bool => $value === 'DELETE http://sonarr.local:8989/api/v3/episodefile/501');

    expect($grabIndex)->not->toBeFalse()
        ->and($deleteIndex)->not->toBeFalse()
        ->and($grabIndex)->toBeLessThan($deleteIndex)
        ->and(MediaReplacementAttempt::first()->status)->toBe(MediaReplacementStatus::Downloading);
});

test('unmonitors the target BEFORE the grab (before a webhook can restore it) and before blocklisting', function (): void {
    fakeExecutor();

    $result = resolve(MediaReplacementActions::class)->execute(replaceActionRequest());

    expect($result['replacement_initiated'])->toBeTrue();

    $requests = Http::recorded()->map(fn (array $pair): string => $pair[0]->method().' '.$pair[0]->url())->values();
    $grabIndex = $requests->search(fn (string $value): bool => $value === 'POST http://sonarr.local:8989/api/v3/release');
    $unmonitorIndex = $requests->search(fn (string $value): bool => $value === 'PUT http://sonarr.local:8989/api/v3/episode/monitor');
    $blocklistIndex = $requests->search(fn (string $value): bool => str_contains($value, 'POST http://sonarr.local:8989/api/v3/history/failed/'));

    expect($unmonitorIndex)->not->toBeFalse()
        ->and($unmonitorIndex)->toBeLessThan($grabIndex)
        ->and($grabIndex)->toBeLessThan($blocklistIndex);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && str_contains($request->url(), '/api/v3/episode/monitor')
        && $request->data()['episodeIds'] === [101]
        && $request->data()['monitored'] === false);
});

test('does not unmonitor an originally-unmonitored target, and still blocklists', function (): void {
    fakeExecutor(['monitored' => false]);

    $result = resolve(MediaReplacementActions::class)->execute(replaceActionRequest());

    expect($result['replacement_initiated'])->toBeTrue()
        ->and($result['blocklist_warning'])->toBeNull();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/episode/monitor'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/history/failed/'));
});

test('skips blocklisting when monitoring could not be suspended', function (): void {
    fakeExecutor(['monitorOk' => false]);

    $result = resolve(MediaReplacementActions::class)->execute(replaceActionRequest());

    expect($result['replacement_initiated'])->toBeTrue()
        ->and($result['blocklist_warning'])->toContain('monitoring could not be suspended');

    // Blocklisting is skipped so the arr never starts a competing auto-search.
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/history/failed/'));
});

test('restores monitoring when the grab is rejected after unmonitoring', function (): void {
    fakeExecutor(['grabStatus' => 400]);

    expect(fn (): array => resolve(MediaReplacementActions::class)->execute(replaceActionRequest()))
        ->toThrow(RuntimeException::class);

    // Suspended then restored (monitored=true) since no replacement will arrive.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && str_contains($request->url(), '/api/v3/episode/monitor')
        && $request->data()['monitored'] === true);
});

test('pins execution to the approved connection when multiple are active', function (): void {
    // The beforeEach connection is sonarr.local (id A). Add a second active
    // Sonarr and approve the request against IT.
    $pinned = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr-b.local:8989', 'api_key' => 'b', 'is_active' => true,
    ]);

    fakeExecutor(); // host-agnostic path matching, so both hosts get faked responses

    $fingerprint = (new ReleaseFingerprint)->make('sonarr', sonarrReplacementRelease());
    $actionRequest = ActionRequest::factory()->create([
        'type' => 'replace_media_file', 'source_service' => 'ai', 'target_service' => 'sonarr',
        'payload' => [
            'service' => 'sonarr',
            'service_connection_id' => $pinned->id,
            'scope' => 'anime',
            'target' => [
                'service' => 'sonarr', 'service_connection_id' => $pinned->id, 'scope' => 'anime',
                'series_id' => 42, 'season_number' => 1, 'episode_numbers' => [1], 'episode_ids' => [101],
                'episode_file_ids' => [501], 'installed_release' => 'Trusted.Anime.S01E01.OLD', 'original_history_id' => 999,
            ],
            'candidate_fingerprint' => $fingerprint,
            'candidate' => ['fingerprint' => $fingerprint, 'title' => 'Trusted.Anime.S01E01.CR', 'confidence' => 98],
            'required_languages' => ['eng'], 'selection_mode' => 'manual', 'original_history_id' => 999,
        ],
    ]);

    resolve(MediaReplacementActions::class)->execute($actionRequest);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), 'sonarr-b.local') && str_contains($request->url(), '/api/v3/release'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'sonarr.local:8989'));

    expect(MediaReplacementAttempt::first()->service_connection_id)->toBe($pinned->id);
});

test('aborts without grabbing or deleting when the installed file changed after approval', function (): void {
    fakeExecutor(['currentFileId' => 777]);

    expect(fn (): array => resolve(MediaReplacementActions::class)->execute(replaceActionRequest()))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/api/v3/release'));
});

test('aborts without deleting when the selected release disappeared', function (): void {
    fakeExecutor(['releases' => []]);

    expect(fn (): array => resolve(MediaReplacementActions::class)->execute(replaceActionRequest()))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/api/v3/release'));
});

test('marks the attempt failed and never deletes when the grab is explicitly rejected (4xx)', function (): void {
    fakeExecutor(['grabStatus' => 400]);

    expect(fn (): array => resolve(MediaReplacementActions::class)->execute(replaceActionRequest()))
        ->toThrow(RuntimeException::class);

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');

    expect(MediaReplacementAttempt::first()->status)->toBe(MediaReplacementStatus::Failed);
});

test('an indeterminate grab (connection error) keeps the attempt trackable instead of failing it', function (): void {
    fakeExecutor(['grabConnection' => true]);

    $result = resolve(MediaReplacementActions::class)->execute(replaceActionRequest());

    expect($result['replacement_initiated'])->toBeFalse()
        ->and($result['grab_outcome'])->toBe('indeterminate')
        ->and($result['status'])->toBe('downloading');

    // No delete or blocklist on an indeterminate grab; attempt stays trackable
    // (non-terminal) so webhooks / reconciliation can resolve it.
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/history/failed/'));

    expect(MediaReplacementAttempt::first()->status)->toBe(MediaReplacementStatus::Downloading);
});

test('a server error (5xx) on the grab is indeterminate, not a rejection', function (): void {
    fakeExecutor(['grabStatus' => 500]);

    $result = resolve(MediaReplacementActions::class)->execute(replaceActionRequest());

    // A 5xx may mean the non-idempotent grab was already accepted, so treat it
    // like connection loss: trackable, no delete/blocklist, no terminal failure.
    expect($result['grab_outcome'])->toBe('indeterminate')
        ->and($result['replacement_initiated'])->toBeFalse();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    expect(MediaReplacementAttempt::first()->status)->toBe(MediaReplacementStatus::Downloading);
});

test('aborts when the approved connection id no longer resolves', function (): void {
    fakeExecutor();

    $fingerprint = (new ReleaseFingerprint)->make('sonarr', sonarrReplacementRelease());
    $actionRequest = ActionRequest::factory()->create([
        'type' => 'replace_media_file', 'source_service' => 'ai', 'target_service' => 'sonarr',
        'payload' => [
            'service' => 'sonarr',
            'service_connection_id' => 999999, // present but deleted since approval
            'scope' => 'anime',
            'target' => ['service' => 'sonarr', 'scope' => 'anime', 'series_id' => 42, 'episode_ids' => [101], 'episode_file_ids' => [501]],
            'candidate_fingerprint' => $fingerprint,
            'candidate' => ['fingerprint' => $fingerprint], 'required_languages' => ['eng'], 'selection_mode' => 'manual',
        ],
    ]);

    expect(fn (): array => resolve(MediaReplacementActions::class)->execute($actionRequest))
        ->toThrow(InvalidArgumentException::class);

    // Never fell through to another active connection.
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/api/v3/release'));
});

test('resumes the interrupted post-grab cleanup without re-grabbing', function (): void {
    fakeExecutor();
    $actionRequest = replaceActionRequest();

    // A prior run grabbed the release (durable grab_accepted_at) then died/failed
    // before completing deletion. Retry must NOT re-POST the release (duplicate
    // download) but MUST resume and finish the delete/blocklist — not report a
    // no-op as success.
    MediaReplacementAttempt::factory()->create([
        'action_request_id' => $actionRequest->id,
        'status' => MediaReplacementStatus::NeedsAttention,
        'grab_accepted_at' => now(),
        'was_monitored' => false,
        'target' => [
            'service' => 'sonarr', 'scope' => 'anime', 'series_id' => 42,
            'episode_ids' => [101], 'episode_file_ids' => [501],
            'installed_release' => 'Trusted.Anime.S01E01.OLD', 'original_history_id' => 999,
        ],
    ]);

    $result = resolve(MediaReplacementActions::class)->execute($actionRequest);

    // No duplicate grab, but the interrupted deletion is resumed.
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/api/v3/release'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_contains($request->url(), '/api/v3/episodefile/501'));
    expect($result['replacement_initiated'])->toBeTrue();
});

test('resume tolerates an already-deleted file (idempotent delete)', function (): void {
    fakeExecutor(['deleteStatus' => 404]);
    $actionRequest = replaceActionRequest();

    MediaReplacementAttempt::factory()->create([
        'action_request_id' => $actionRequest->id,
        'status' => MediaReplacementStatus::NeedsAttention,
        'grab_accepted_at' => now(),
        'was_monitored' => false,
        'target' => [
            'service' => 'sonarr', 'scope' => 'anime', 'series_id' => 42,
            'episode_ids' => [101], 'episode_file_ids' => [501],
            'installed_release' => 'Trusted.Anime.S01E01.OLD', 'original_history_id' => 999,
        ],
    ]);

    // A 404 on the file delete (already gone) must not fail the resume.
    $result = resolve(MediaReplacementActions::class)->execute($actionRequest);

    expect($result['replacement_initiated'])->toBeTrue();
});

test('a retry reuses the existing attempt row instead of hitting a duplicate key', function (): void {
    fakeExecutor();
    $actionRequest = replaceActionRequest();

    // A prior run already created an attempt for this ActionRequest and failed.
    // The Action Queue reuses the same ActionRequest on Retry, and
    // action_request_id is unique, so a plain create() would duplicate-key here.
    MediaReplacementAttempt::factory()->create([
        'action_request_id' => $actionRequest->id,
        'status' => MediaReplacementStatus::Failed,
        'failure_reason' => 'Replacement grab was rejected; the current file was left untouched.',
    ]);

    $result = resolve(MediaReplacementActions::class)->execute($actionRequest);

    expect($result['replacement_initiated'])->toBeTrue()
        ->and(MediaReplacementAttempt::where('action_request_id', $actionRequest->id)->count())->toBe(1)
        ->and(MediaReplacementAttempt::first()->status)->toBe(MediaReplacementStatus::Downloading)
        ->and(MediaReplacementAttempt::first()->failure_reason)->toBeNull();
});

test('does not regress a terminal state the real tracker set during execution', function (): void {
    // Drive the REAL tracker (not a raw DB write) as a fast Download webhook
    // arriving while the executor is still deleting: it correlates by download
    // id, busts the cache, re-inspects, and terminalizes the attempt. The
    // executor must not then write `downloading` back over that terminal state.
    fakeExecutor([
        'onDelete' => function (): void {
            MediaReplacementAttempt::query()->update(['download_id' => 'DL-INTERLEAVE']);
            resolve(MediaReplacementTracker::class)->verifyDownload(
                ServiceConnection::query()->firstOrFail(),
                ['eventType' => 'Download', 'series' => ['id' => 42], 'downloadId' => 'DL-INTERLEAVE'],
            );
        },
    ]);

    resolve(MediaReplacementActions::class)->execute(replaceActionRequest());

    // The tracker terminalized it (needs_attention: the fixture file still has
    // only Japanese subtitles vs required English); the executor must not have
    // regressed it back to downloading.
    expect(MediaReplacementAttempt::first()->status)->toBe(MediaReplacementStatus::NeedsAttention);
});

test('marks the attempt needs_attention when deletion fails after a successful grab', function (): void {
    fakeExecutor(['deleteOk' => false]);

    expect(fn (): array => resolve(MediaReplacementActions::class)->execute(replaceActionRequest()))
        ->toThrow(RuntimeException::class);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/api/v3/release'));

    expect(MediaReplacementAttempt::first()->status)->toBe(MediaReplacementStatus::NeedsAttention);
});
