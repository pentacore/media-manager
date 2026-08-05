<?php

declare(strict_types=1);

use App\Enums\MediaReplacementStatus;
use App\Events\MediaReplacementAttemptChanged;
use App\Jobs\SweepCompetingGrabs;
use App\Models\ActionRequest;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Services\Actions\SharedMediaTargetLock;
use App\Services\MediaReplacement\MediaReplacementActions;
use App\Services\MediaReplacement\MediaReplacementTracker;
use App\Services\MediaReplacement\ReleaseFingerprint;
use App\Settings\MediaReplacementSettings;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

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
 * @param  array{grabOk?: bool, grabConnection?: bool, grabStatus?: int, deleteOk?: bool, monitorOk?: bool, monitored?: bool, currentFileId?: int, subtitles?: string, releases?: list<array<string, mixed>>, queueRecords?: list<array<string, mixed>>, onDelete?: callable}  $opts
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
    $subtitles = $opts['subtitles'] ?? 'Japanese';
    $releases = $opts['releases'] ?? [sonarrReplacementRelease()];
    $queueRecords = $opts['queueRecords'] ?? [];
    $onDelete = $opts['onDelete'] ?? null;

    Http::fake(function (Request $request) use ($grabConnection, $grabStatus, $deleteStatus, $monitorOk, $monitored, $currentFileId, $subtitles, $releases, $queueRecords, $onDelete) {
        $method = $request->method();
        $url = $request->url();

        throw_if($method === 'POST' && str_contains($url, '/api/v3/release') && $grabConnection, ConnectionException::class, 'Connection timed out');

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
                'mediaInfo' => ['subtitles' => $subtitles],
            ]),
            str_contains($url, '/api/v3/history/failed/') => Http::response([], 200),
            str_contains($url, '/api/v3/history') => Http::response(['records' => [
                ['id' => 999, 'eventType' => 'grabbed', 'episodeId' => 101],
            ]]),
            $method === 'GET' && str_contains($url, '/api/v3/queue') => Http::response(['records' => $queueRecords]),
            $method === 'DELETE' && str_contains($url, '/api/v3/queue/') => Http::response([], 200),
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

test('an approved rejected candidate tells Sonarr to override its release decision', function (): void {
    // Mirror a real Sonarr search response: seriesId/episodeIds are write-only
    // request fields Sonarr never returns — only mapped* fields come back.
    $release = sonarrReplacementRelease();
    unset($release['episodeIds']);
    $release['rejections'] = ['Existing file on disk has a equal or higher Custom Format score: 143600'];
    $release['mappedSeriesId'] = 42;
    $release['mappedEpisodeInfo'] = [['id' => 101, 'seasonNumber' => 1, 'episodeNumber' => 1]];
    fakeExecutor(['releases' => [$release]]);

    $fingerprint = (new ReleaseFingerprint)->make('sonarr', [...$release, 'episodeIds' => [101]]);
    $actionRequest = replaceActionRequest();
    $payload = $actionRequest->payload;
    $payload['candidate_fingerprint'] = $fingerprint;
    $payload['candidate'] = [
        'fingerprint' => $fingerprint,
        'title' => $release['title'],
        'confidence' => 98,
        'requires_approval' => true,
        'rejection_reasons' => $release['rejections'],
    ];
    $actionRequest->forceFill(['payload' => $payload])->save();

    resolve(MediaReplacementActions::class)->execute($actionRequest);

    // Sonarr validates seriesId + non-empty episodeIds whenever shouldOverride
    // is set; omitting them 500s with "Value can not be null (release.SeriesId)".
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/api/v3/release')
        && $request->data()['shouldOverride'] === true
        && $request->data()['seriesId'] === 42
        && $request->data()['episodeIds'] === [101]);
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

test('a held Bazarr subtitle lock does not block a media replacement', function (): void {
    fakeExecutor();
    $connectionId = ServiceConnection::query()->firstOrFail()->id;

    // A Bazarr subtitle operation holds the shared installed-file lock for the
    // same episode. Replacement no longer coordinates with Bazarr: by the time a
    // replacement is queued Bazarr has already failed to supply the subtitle, so
    // the replacement proceeds regardless of what Bazarr is doing.
    $lock = Cache::lock(SharedMediaTargetLock::key($connectionId, 'episode', 101), 120);
    expect($lock->get())->toBeTrue();

    try {
        $result = resolve(MediaReplacementActions::class)->execute(replaceActionRequest());

        expect($result['replacement_initiated'])->toBeTrue();

        $requests = Http::recorded()->map(fn (array $pair): string => $pair[0]->method().' '.$pair[0]->url())->values();
        $grabIndex = $requests->search(fn (string $value): bool => $value === 'POST http://sonarr.local:8989/api/v3/release');
        $deleteIndex = $requests->search(fn (string $value): bool => $value === 'DELETE http://sonarr.local:8989/api/v3/episodefile/501');

        expect($grabIndex)->not->toBeFalse()
            ->and($deleteIndex)->not->toBeFalse()
            ->and($grabIndex)->toBeLessThan($deleteIndex);
    } finally {
        $lock->release();
    }
});

test('aborts a destructive replacement when the pinned connection was deactivated after approval', function (): void {
    fakeExecutor();
    $serviceConnection = ServiceConnection::query()->firstOrFail();
    $serviceConnection->update(['is_active' => false]);

    $fingerprint = (new ReleaseFingerprint)->make('sonarr', sonarrReplacementRelease());
    $actionRequest = ActionRequest::factory()->create([
        'type' => 'replace_media_file', 'source_service' => 'ai', 'target_service' => 'sonarr',
        'payload' => [
            'service' => 'sonarr',
            'service_connection_id' => $serviceConnection->id,
            'scope' => 'anime',
            'target' => [
                'service' => 'sonarr', 'service_connection_id' => $serviceConnection->id, 'scope' => 'anime',
                'series_id' => 42, 'episode_ids' => [101], 'episode_file_ids' => [501],
            ],
            'candidate_fingerprint' => $fingerprint,
            'candidate' => ['fingerprint' => $fingerprint], 'required_languages' => ['eng'], 'selection_mode' => 'manual',
        ],
    ]);

    expect(fn (): array => resolve(MediaReplacementActions::class)->execute($actionRequest))
        ->toThrow(InvalidArgumentException::class);

    // A deactivated connection must abort BEFORE any destructive write.
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/api/v3/release'));
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
        'failure_reason' => 'deletion_failed',
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
    expect($result['replacement_initiated'])->toBeTrue()
        // The persisted row is reopened to a trackable state (not left terminal),
        // so the eventual Grab/Download webhook can still correlate and verify it.
        ->and(MediaReplacementAttempt::first()->status)->toBe(MediaReplacementStatus::Downloading)
        ->and(MediaReplacementAttempt::first()->completed_at)->toBeNull();
});

test('a resume whose attempt is pruned mid-flight fails with a stated reason', function (): void {
    fakeExecutor();
    $actionRequest = replaceActionRequest();

    $attempt = MediaReplacementAttempt::factory()->create([
        'action_request_id' => $actionRequest->id,
        'status' => MediaReplacementStatus::NeedsAttention,
        'grab_accepted_at' => now(),
        'failure_reason' => 'deletion_failed',
        'was_monitored' => false,
        'target' => [
            'service' => 'sonarr', 'scope' => 'anime', 'series_id' => 42,
            'episode_ids' => [101], 'episode_file_ids' => [501],
            'installed_release' => 'Trusted.Anime.S01E01.OLD', 'original_history_id' => 999,
        ],
    ]);

    // MediaReplacementAttempt is MassPrunable and model:prune is scheduled, so a
    // long-settled attempt an operator retries is exactly the row that can vanish
    // between the executor's load and its re-read. Deleting on the reopen write puts
    // the prune in that window deterministically. Before this guard the re-read was
    // findOrFail and the operator got an unhandled ModelNotFoundException.
    MediaReplacementAttempt::updated(static function (MediaReplacementAttempt $updated) use ($attempt): void {
        if ($updated->id === $attempt->id) {
            MediaReplacementAttempt::withoutEvents(static fn (): mixed => MediaReplacementAttempt::query()->whereKey($attempt->id)->delete());
        }
    });

    expect(fn (): array => resolve(MediaReplacementActions::class)->execute($actionRequest))
        ->toThrow(InvalidArgumentException::class, 'pruned while this retry was resuming');

    // Nothing destructive ran on the way out.
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/api/v3/episodefile/'));
});

test('resumes cleanup after a worker crash left it unfinished with no failure marker', function (): void {
    fakeExecutor();
    $actionRequest = replaceActionRequest();

    // Hard kill after grab_accepted_at was saved but before cleanup finished:
    // status is still `downloading`, failure_reason is null, and
    // cleanup_completed_at is null. `deletion_failed` alone would miss this — the
    // durable signal that cleanup is unfinished is cleanup_completed_at === null.
    MediaReplacementAttempt::factory()->create([
        'action_request_id' => $actionRequest->id,
        'status' => MediaReplacementStatus::Downloading,
        'grab_accepted_at' => now(),
        'cleanup_completed_at' => null,
        'failure_reason' => null,
        'was_monitored' => false,
        'target' => [
            'service' => 'sonarr', 'scope' => 'anime', 'series_id' => 42,
            'episode_ids' => [101], 'episode_file_ids' => [501],
            'installed_release' => 'Trusted.Anime.S01E01.OLD', 'original_history_id' => 999,
        ],
    ]);

    $result = resolve(MediaReplacementActions::class)->execute($actionRequest);

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/api/v3/release'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_contains($request->url(), '/api/v3/episodefile/501'));
    expect($result['replacement_initiated'])->toBeTrue()
        ->and(MediaReplacementAttempt::first()->cleanup_completed_at)->not->toBeNull();
});

test('resume finishes cleanup without clobbering a webhook terminal outcome', function (): void {
    fakeExecutor();
    $actionRequest = replaceActionRequest();

    // A Download webhook verified the import during the prior run, which then
    // crashed before completing cleanup (cleanup_completed_at null). The resume
    // must finish the cleanup but NOT reopen/clobber the verified result.
    MediaReplacementAttempt::factory()->create([
        'action_request_id' => $actionRequest->id,
        'status' => MediaReplacementStatus::Verified,
        'grab_accepted_at' => now(),
        'cleanup_completed_at' => null,
        'was_monitored' => false,
        'verification' => ['required' => ['eng'], 'found' => ['eng'], 'missing' => []],
        'target' => [
            'service' => 'sonarr', 'scope' => 'anime', 'series_id' => 42,
            'episode_ids' => [101], 'episode_file_ids' => [501],
            'installed_release' => 'Trusted.Anime.S01E01.OLD', 'original_history_id' => 999,
        ],
    ]);

    $result = resolve(MediaReplacementActions::class)->execute($actionRequest);

    // No re-grab; the verified terminal status is preserved and completed cleanup.
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/api/v3/release'));
    expect(MediaReplacementAttempt::first()->status)->toBe(MediaReplacementStatus::Verified)
        ->and(MediaReplacementAttempt::first()->cleanup_completed_at)->not->toBeNull()
        // The returned result reflects the PERSISTED terminal status, not a
        // hardcoded 'downloading' the ActionRequest would otherwise record.
        ->and($result['status'])->toBe('verified');

    // Blocklisting is still safe here (cleanup phase open → target stays
    // suspended), so the bad release is blocklisted rather than left eligible.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/api/v3/history/failed/'));
});

test('a resume re-asserts the suspension before blocklisting instead of trusting the stored flag', function (): void {
    fakeExecutor();
    $actionRequest = replaceActionRequest();

    // Prior run left monitoring_suspended=false on a monitored target. That flag is
    // NOT trustworthy at resume time: it has been sitting in the database since the run
    // that died, and the reconciliation repair pass restores monitoring on settled
    // attempts. A resume does rewrite started_at to now, which excludes a repair pass
    // that reads the row after that write — but one already holding an earlier read can
    // still issue its monitor PUT afterwards, so the flag can be stale in either
    // direction. Blocklisting on the strength of the stored flag would either
    // needlessly decline (as it used to) or, worse, blocklist a target something
    // else has remonitored. The resume re-issues the unmonitor and decides from
    // that, so failure_reason plays no part in the decision at all.
    MediaReplacementAttempt::factory()->create([
        'action_request_id' => $actionRequest->id,
        'status' => MediaReplacementStatus::NeedsAttention,
        'grab_accepted_at' => now(),
        'was_monitored' => true,
        'monitoring_suspended' => false,
        'failure_reason' => 'deletion_failed',
        'target' => [
            'service' => 'sonarr', 'scope' => 'anime', 'series_id' => 42,
            'episode_ids' => [101], 'episode_file_ids' => [501],
            'installed_release' => 'Trusted.Anime.S01E01.OLD', 'original_history_id' => 999,
        ],
    ]);

    resolve(MediaReplacementActions::class)->execute($actionRequest);

    $requests = Http::recorded()
        ->map(fn (array $pair): string => $pair[0]->method().' '.$pair[0]->url().' '.json_encode($pair[0]->data()))
        ->values();
    $unmonitorIndex = $requests->search(fn (string $value): bool => str_contains($value, 'PUT http://sonarr.local:8989/api/v3/episode/monitor')
        && str_contains($value, '"monitored":false'));
    $blocklistIndex = $requests->search(fn (string $value): bool => str_contains($value, 'POST http://sonarr.local:8989/api/v3/history/failed/'));

    // Suspension re-asserted FIRST, then the blocklist.
    expect($unmonitorIndex)->not->toBeFalse()
        ->and($blocklistIndex)->not->toBeFalse()
        ->and($unmonitorIndex)->toBeLessThan($blocklistIndex);

    // And the re-asserted suspension is persisted, so it is the import event that
    // lifts it rather than a flag that was already stale.
    expect(MediaReplacementAttempt::query()->where('action_request_id', $actionRequest->id)->sole()->monitoring_suspended)
        ->toBeTrue();
});

test('a resume that cannot re-assert the suspension declines to blocklist', function (): void {
    // The unmonitor PUT fails, so the target is monitored and markHistoryFailed
    // would launch the competing auto-search. The rule is unchanged: a monitored
    // target that cannot be suspended must not be blocklisted.
    fakeExecutor(['monitorOk' => false]);
    $actionRequest = replaceActionRequest();

    MediaReplacementAttempt::factory()->create([
        'action_request_id' => $actionRequest->id,
        'status' => MediaReplacementStatus::NeedsAttention,
        'grab_accepted_at' => now(),
        'was_monitored' => true,
        'monitoring_suspended' => true,
        'failure_reason' => 'deletion_failed',
        'target' => [
            'service' => 'sonarr', 'scope' => 'anime', 'series_id' => 42,
            'episode_ids' => [101], 'episode_file_ids' => [501],
            'installed_release' => 'Trusted.Anime.S01E01.OLD', 'original_history_id' => 999,
        ],
    ]);

    $result = resolve(MediaReplacementActions::class)->execute($actionRequest);

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/api/v3/history/failed/'));

    expect($result['blocklist_warning'])->toContain('monitoring could not be suspended')
        // The stored true SURVIVES. A failed unmonitor PUT is not evidence that the
        // target is monitored — it is evidence of nothing — and this row already
        // records that an earlier run suspended it successfully. Clearing the flag
        // would discard the only durable record of that outstanding restore.
        ->and(MediaReplacementAttempt::query()->where('action_request_id', $actionRequest->id)->sole()->monitoring_suspended)
        ->toBeTrue();
});

test('a resume whose unmonitor fails keeps the restore obligation a later actor can act on', function (): void {
    // The correlated failure: arr trouble is WHY run 1 died, so the retry hits the
    // same flaky arr. Run 1 unmonitored the target (monitoring_suspended = true) and
    // died mid-cleanup; the resume's fresh unmonitor PUT now fails.
    //
    // If that failure cleared the flag, every actor that could ever put monitoring
    // back would stand down: restoreSuspendedMonitoring() returns true without an
    // arr call, verifyDownload() computes needsRestore = false and terminalizes
    // Verified, and both reconciliation passes filter on monitoring_suspended = true
    // so neither would ever select the row. The target would silently stop receiving
    // upgrades forever. That is the defect class, so the flag must survive.
    // The arr rejects monitor writes for the resumed run and recovers afterwards.
    // Registered BEFORE fakeExecutor() because merged stubs are tried in order and
    // the first non-null response wins; returning null defers to fakeExecutor().
    $arrAcceptsMonitorWrites = false;

    Http::fake(function (Request $request) use (&$arrAcceptsMonitorWrites): ?PromiseInterface {
        if ($request->method() !== 'PUT' || ! str_contains($request->url(), '/api/v3/episode/monitor')) {
            return null;
        }

        return Http::response([], $arrAcceptsMonitorWrites ? 200 : 500);
    });

    fakeExecutor();
    $actionRequest = replaceActionRequest();

    MediaReplacementAttempt::factory()->create([
        'action_request_id' => $actionRequest->id,
        'service_connection_id' => ServiceConnection::query()->firstOrFail()->id,
        'status' => MediaReplacementStatus::Downloading,
        'grab_accepted_at' => now(),
        'cleanup_completed_at' => null,
        'was_monitored' => true,
        'monitoring_suspended' => true,
        'target' => [
            'service' => 'sonarr', 'scope' => 'anime', 'series_id' => 42,
            'episode_ids' => [101], 'episode_file_ids' => [501],
            'installed_release' => 'Trusted.Anime.S01E01.OLD', 'original_history_id' => 999,
        ],
    ]);

    resolve(MediaReplacementActions::class)->execute($actionRequest);

    $mediaReplacementAttempt = MediaReplacementAttempt::query()->where('action_request_id', $actionRequest->id)->sole();

    expect($mediaReplacementAttempt->monitoring_suspended)->toBeTrue();

    // And the obligation is genuinely actionable rather than a flag nobody reads:
    // once the arr recovers the restore actually reaches it, instead of being
    // short-circuited as "there is nothing to restore". The resumed run itself only
    // ever sent monitored: false, so a monitored: true PUT can only be this restore.
    $arrAcceptsMonitorWrites = true;

    expect(resolve(MediaReplacementTracker::class)->restoreSuspendedMonitoring($mediaReplacementAttempt))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && str_contains($request->url(), '/api/v3/episode/monitor')
        && $request->data()['monitored'] === true);

    expect($mediaReplacementAttempt->fresh()->monitoring_suspended)->toBeFalse();
});

/**
 * Run 1 suspended the target, its grab was rejected, and its restore PUT then failed —
 * so the target is genuinely unmonitored and the row records that with
 * monitoring_suspended = true. A Retry lands on the FRESH path (no accepted grab to
 * resume), which resets the row.
 *
 * @param  array<string, mixed>  $overrides
 */
function attemptOwedARestore(int $actionRequestId, array $overrides = []): MediaReplacementAttempt
{
    return MediaReplacementAttempt::factory()->create(array_replace([
        'action_request_id' => $actionRequestId,
        'service_connection_id' => ServiceConnection::query()->firstOrFail()->id,
        'status' => MediaReplacementStatus::Failed,
        'failure_reason' => 'Replacement grab was rejected and monitoring could not be restored; needs manual review.',
        'grab_attempted_at' => null,
        'grab_accepted_at' => null,
        'was_monitored' => true,
        'monitoring_suspended' => true,
        'target' => [
            'service' => 'sonarr', 'scope' => 'anime', 'series_id' => 42,
            'episode_ids' => [101], 'episode_file_ids' => [501],
            'installed_release' => 'Trusted.Anime.S01E01.OLD', 'original_history_id' => 999,
        ],
    ], $overrides));
}

test('a retry does not discard a suspension an earlier run failed to restore', function (): void {
    // The retry's own unmonitor PUT fails too — the correlated case, since arr trouble
    // is what left the restore undone in the first place. Resetting the flag and then
    // writing the fresh (false) result would discard the only durable record that a
    // restore is owed, and strand the row outside the reconciliation repair pass,
    // which selects on monitoring_suspended = true. A failed PUT is evidence of
    // nothing; it certainly is not evidence that the target is monitored.
    fakeExecutor(['monitorOk' => false]);
    $actionRequest = replaceActionRequest();

    attemptOwedARestore($actionRequest->id);

    $result = resolve(MediaReplacementActions::class)->execute($actionRequest);

    // The blocklist decision is unchanged and still driven by the FRESH attempt: a
    // target we could not suspend now must not be blocklisted.
    expect($result['blocklist_warning'])->toContain('monitoring could not be suspended')
        ->and(MediaReplacementAttempt::query()->where('action_request_id', $actionRequest->id)->sole()->monitoring_suspended)
        ->toBeTrue();
});

test('the retry reset itself writes the inherited suspension, before any unmonitor result exists', function (): void {
    // The reset inside updateOrCreate is load-bearing on its own: it protects a crash
    // BETWEEN that write and the unmonitor PUT that follows it. In that interval the
    // persisted row is the ONLY record of the obligation — $owedRestore lives in a
    // dead process's memory, and nothing has written monitoring_suspended a second
    // time yet.
    //
    // The sibling test above cannot pin this. It only inspects the row after the run,
    // by which point the post-unmonitor write has recomputed the flag from
    // $owedRestore — read off the pre-reset row, so it survives the reset regressing
    // to an unconditional null. So observe the row at exactly the vulnerable instant
    // instead: from inside the fake serving the unmonitor PUT, which runs after
    // updateOrCreate has committed and before anything else touches the flag.
    $actionRequest = replaceActionRequest();

    attemptOwedARestore($actionRequest->id);

    $observed = false;
    $flagAtUnmonitor = null;

    // Registered BEFORE fakeExecutor(): merged stubs are tried in order and the first
    // non-null response wins, so returning null defers the actual response to it.
    Http::fake(function (Request $request) use (&$observed, &$flagAtUnmonitor, $actionRequest): ?PromiseInterface {
        if ($observed || $request->method() !== 'PUT' || ! str_contains($request->url(), '/api/v3/episode/monitor')) {
            return null;
        }

        $observed = true;
        $flagAtUnmonitor = MediaReplacementAttempt::query()
            ->where('action_request_id', $actionRequest->id)
            ->sole()
            ->monitoring_suspended;

        return null;
    });

    fakeExecutor(['monitorOk' => false]);

    resolve(MediaReplacementActions::class)->execute($actionRequest);

    // $observed guards against a vacuous pass: the unmonitor PUT must actually have
    // been issued for the observation to mean anything. The flag must then be true —
    // an unconditional null would mean a crash one statement later left no record that
    // a restore was owed, and every actor that could discharge it selects on true.
    expect($observed)->toBeTrue()
        ->and($flagAtUnmonitor)->toBeTrue();
});

test('a retry whose grab is rejected still tries to discharge an inherited restore', function (): void {
    // Same inherited obligation, but this grab is rejected too. The restore branch used
    // to run only when THIS run suspended monitoring, so an inherited suspension was
    // silently skipped: no restore attempted, and the operator message did not mention
    // monitoring either.
    fakeExecutor(['monitorOk' => false, 'grabStatus' => 400]);
    $actionRequest = replaceActionRequest();

    attemptOwedARestore($actionRequest->id);

    expect(fn (): array => resolve(MediaReplacementActions::class)->execute($actionRequest))
        ->toThrow(RuntimeException::class);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && str_contains($request->url(), '/api/v3/episode/monitor')
        && $request->data()['monitored'] === true);

    $mediaReplacementAttempt = MediaReplacementAttempt::query()->where('action_request_id', $actionRequest->id)->sole();

    // It failed, so the obligation survives for the repair pass and the operator is
    // told monitoring is the outstanding problem.
    expect($mediaReplacementAttempt->monitoring_suspended)->toBeTrue()
        ->and($mediaReplacementAttempt->status)->toBe(MediaReplacementStatus::Failed)
        ->and($mediaReplacementAttempt->failure_reason)->toContain('monitoring could not be restored');
});

test('preserves the original was_monitored across a retry', function (): void {
    // ARR is currently unmonitored (a prior rejected-grab left it so), but the
    // original attempt recorded was_monitored=true. Re-inspection sees false;
    // the executor must keep the original true so restoration is not skipped.
    fakeExecutor(['monitored' => false]);
    $actionRequest = replaceActionRequest();

    MediaReplacementAttempt::factory()->create([
        'action_request_id' => $actionRequest->id,
        'status' => MediaReplacementStatus::Failed,
        'was_monitored' => true,
        'grab_accepted_at' => null,
    ]);

    resolve(MediaReplacementActions::class)->execute($actionRequest);

    expect(MediaReplacementAttempt::first()->was_monitored)->toBeTrue();
});

test('resume tolerates an already-deleted file (idempotent delete)', function (): void {
    fakeExecutor(['deleteStatus' => 404]);
    $actionRequest = replaceActionRequest();

    MediaReplacementAttempt::factory()->create([
        'action_request_id' => $actionRequest->id,
        'status' => MediaReplacementStatus::NeedsAttention,
        'grab_accepted_at' => now(),
        'failure_reason' => 'deletion_failed',
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
    // id, busts the cache, and re-inspects. Because cleanup is still in flight
    // (monitoring suspended, cleanup_completed_at null), restoration is deferred
    // to the executor, so the tracker leaves the attempt PENDING with its
    // verification stored. The executor then finalizes it to a terminal state.
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

    // The executor's finalizeAfterCleanup call terminalized the pending verification
    // as needs_attention: the fixture file still has only Japanese subtitles vs
    // required English. The finalizer restored monitoring successfully, so the sole
    // failure reason is the missing subtitles, not a false restore failure.
    expect(MediaReplacementAttempt::first()->status)->toBe(MediaReplacementStatus::NeedsAttention)
        ->and(MediaReplacementAttempt::first()->failure_reason)->toBe('imported_subtitles_missing_required_language');

    // Blocklisting still runs and is safe: because the tracker deferred the
    // remonitor, the target stayed suspended throughout the cleanup, so
    // markHistoryFailed cannot trigger a competing auto-search.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/api/v3/history/failed/'));
});

test('marks the attempt needs_attention when deletion fails after a successful grab', function (): void {
    Event::fake([MediaReplacementAttemptChanged::class]);
    fakeExecutor(['deleteOk' => false]);

    expect(fn (): array => resolve(MediaReplacementActions::class)->execute(replaceActionRequest()))
        ->toThrow(RuntimeException::class);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/api/v3/release'));

    expect(MediaReplacementAttempt::first()->status)->toBe(MediaReplacementStatus::NeedsAttention);
    // The terminal transition announces itself exactly once so a correlated
    // subtitle case can react.
    Event::assertDispatchedTimes(MediaReplacementAttemptChanged::class, 1);
});

test('a retry after an indeterminate grab resets the stale cleanup checkpoint so a fast webhook cannot remonitor before blocklisting', function (): void {
    $actionRequest = replaceActionRequest();

    // A prior INDETERMINATE run set cleanup_completed_at (its only synchronous
    // marker) but never accepted a grab (grab_accepted_at null), then the worker
    // died before the ActionRequest completed. On Retry this row is reused via the
    // normal claim path. If the stale checkpoint were NOT reset, a Download during
    // the new cleanup would see cleanupDone=true and remonitor the target BEFORE
    // the executor blocklists — reopening the competing auto-search race.
    MediaReplacementAttempt::factory()->create([
        'action_request_id' => $actionRequest->id,
        'status' => MediaReplacementStatus::Downloading,
        'grab_accepted_at' => null,
        'cleanup_completed_at' => now(),
        'was_monitored' => true,
        'target' => [
            'service' => 'sonarr', 'scope' => 'anime', 'series_id' => 42,
            'episode_ids' => [101], 'episode_file_ids' => [501],
            'installed_release' => 'Trusted.Anime.S01E01.OLD', 'original_history_id' => 999,
        ],
    ]);

    // A Grab webhook records the download id, then a Download webhook arrives while
    // the executor is deleting the reviewed file.
    fakeExecutor([
        'onDelete' => function (): void {
            MediaReplacementAttempt::query()->update(['download_id' => 'DL-STALE']);
            resolve(MediaReplacementTracker::class)->verifyDownload(
                ServiceConnection::query()->firstOrFail(),
                ['eventType' => 'Download', 'series' => ['id' => 42], 'downloadId' => 'DL-STALE'],
            );
        },
    ]);

    resolve(MediaReplacementActions::class)->execute($actionRequest);

    $requests = Http::recorded()
        ->map(fn (array $pair): string => $pair[0]->method().' '.$pair[0]->url().' '.json_encode($pair[0]->data()))
        ->values();
    $blocklistIndex = $requests->search(fn (string $value): bool => str_contains($value, 'POST http://sonarr.local:8989/api/v3/history/failed/'));
    $remonitorIndex = $requests->search(fn (string $value): bool => str_contains($value, 'PUT http://sonarr.local:8989/api/v3/episode/monitor')
        && str_contains($value, '"monitored":true'));

    // The only remonitor is the one finalizeAfterCleanup performs at the end of the
    // run, AFTER blocklisting; the fast webhook did not remonitor because the stale
    // checkpoint was reset.
    expect($blocklistIndex)->not->toBeFalse()
        ->and($remonitorIndex)->not->toBeFalse()
        ->and($blocklistIndex)->toBeLessThan($remonitorIndex);
});

test('a clean-subtitles Download arriving during cleanup stays pending, then the executor finalizes it verified', function (): void {
    // The replacement imports with the required subtitles, and the Download webhook
    // fires while the executor is still deleting the old file (monitoring suspended,
    // cleanup_completed_at null). Restoration is deferred to the executor, so the
    // tracker must leave the attempt PENDING with its verification stored rather
    // than falsely terminalizing it as restore_monitoring_failed.
    fakeExecutor([
        'subtitles' => 'English',
        'onDelete' => function (): void {
            MediaReplacementAttempt::query()->update(['download_id' => 'DL-CLEAN']);
            resolve(MediaReplacementTracker::class)->verifyDownload(
                ServiceConnection::query()->firstOrFail(),
                ['eventType' => 'Download', 'series' => ['id' => 42], 'downloadId' => 'DL-CLEAN'],
            );
        },
    ]);

    $result = resolve(MediaReplacementActions::class)->execute(replaceActionRequest());

    // Once cleanup closed, finalizeAfterCleanup restored monitoring and terminalized
    // the pending verification as Verified, with no false restore-failure reason.
    expect(MediaReplacementAttempt::first()->status)->toBe(MediaReplacementStatus::Verified)
        ->and(MediaReplacementAttempt::first()->failure_reason)->toBeNull()
        ->and($result['status'])->toBe('verified');

    // The blocklist ran (target stayed suspended through cleanup) and was safe.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/api/v3/history/failed/'));
});

test('the executor does not restore monitoring after blocklisting, so the queued re-search cannot grab', function (): void {
    fakeExecutor(['monitored' => true]);

    $actionRequest = replaceActionRequest();

    resolve(MediaReplacementActions::class)->execute($actionRequest);

    // Monitoring is suspended before the grab and must STAY suspended: the arr
    // queues its re-search asynchronously and runs it seconds later.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && str_contains($request->url(), '/api/v3/episode/monitor')
        && $request->data()['monitored'] === false);

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT'
        && str_contains($request->url(), '/api/v3/episode/monitor')
        && $request->data()['monitored'] === true);

    $mediaReplacementAttempt = MediaReplacementAttempt::query()->where('action_request_id', $actionRequest->id)->sole();

    expect($mediaReplacementAttempt->monitoring_suspended)->toBeTrue()
        ->and($mediaReplacementAttempt->cleanup_completed_at)->not->toBeNull();
});

test('the executor reports the sweep result and does not remove anything before the grab webhook lands', function (): void {
    // The attempt's download_id is still null during the executor's own cleanup, so
    // the sweeper cannot tell the replacement's own download apart from a competing
    // one. It refuses at the arming gate, which short-circuits BEFORE it reads the
    // queue at all — so these rows are never even fetched. They are here as a
    // negative control: had the gate not short-circuited, this is a row it would
    // have deleted.
    fakeExecutor([
        'queueRecords' => [
            ['id' => 910, 'seriesId' => 42, 'episodeId' => 101, 'downloadId' => 'DL-RACE', 'title' => 'Competing.Release'],
        ],
    ]);

    $actionRequest = replaceActionRequest();

    $result = resolve(MediaReplacementActions::class)->execute($actionRequest);

    expect($result['competing_grabs_removed'])->toBe(0);

    // No read, therefore no removal.
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/queue'));
});

test('the executor arms the delayed sweep passes as the backstop for the queued re-search', function (): void {
    // The synchronous sweep above cannot see a grab that has not happened yet, so
    // the delayed passes are the actor that actually cleans one up.
    Queue::fake([SweepCompetingGrabs::class]);

    fakeExecutor();

    $actionRequest = replaceActionRequest();

    resolve(MediaReplacementActions::class)->execute($actionRequest);

    $mediaReplacementAttempt = MediaReplacementAttempt::query()->where('action_request_id', $actionRequest->id)->sole();

    Queue::assertPushed(
        SweepCompetingGrabs::class,
        fn (SweepCompetingGrabs $sweepCompetingGrabs): bool => $sweepCompetingGrabs->attemptId === $mediaReplacementAttempt->id
            && $sweepCompetingGrabs->pass === 0,
    );
});

test('no sweep passes are armed when no blocklist ran, since nothing queued a re-search', function (): void {
    Queue::fake([SweepCompetingGrabs::class]);

    // Suspension failed, so the blocklist is skipped. With no queued re-search there
    // is no competing grab to expect, and the passes could then only remove a
    // same-target download that simply is not ours — with removeFromClient: true.
    fakeExecutor(['monitorOk' => false]);

    resolve(MediaReplacementActions::class)->execute(replaceActionRequest());

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/history/failed/'));
    Queue::assertNotPushed(SweepCompetingGrabs::class);
});

test('no sweep passes are armed when the blocklist could not identify the history record', function (): void {
    Queue::fake([SweepCompetingGrabs::class]);

    fakeExecutor();

    // Monitoring WAS suspended, so blocklisting was allowed — but the approval never
    // pinned a unique history record, so blocklistOriginal returns early and no
    // blocklist runs. Gating the passes on the allowance rather than on the blocklist
    // actually succeeding would arm them here, with no queued re-search to defend
    // against. (markHistoryFailed throwing is the other such path.)
    $actionRequest = replaceActionRequest();
    $actionRequest->forceFill(['payload' => [...$actionRequest->payload, 'original_history_id' => null]])->save();

    resolve(MediaReplacementActions::class)->execute($actionRequest);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/history/failed/'));
    Queue::assertNotPushed(SweepCompetingGrabs::class);
});

test('a resumed attempt is not repaired by a reconciliation pass running in the same window', function (): void {
    fakeExecutor();
    $actionRequest = replaceActionRequest();

    // Days old, terminal with a NON-recoverable reason, monitoring still suspended,
    // cleanup never closed. The resume's conditional reopen deliberately does not
    // match this status, so the row stays terminal for the whole run — which is
    // exactly the shape the reconciliation repair pass selects on. Its age is the
    // ORIGINAL attempt's, so unless the resume rewrites started_at an hourly repair
    // pass treats a live executor's row as fair game: it remonitors the target
    // mid-cleanup and the blocklist that follows fires the arr's queued re-search
    // against a monitored target. SweepCompetingGrabs cannot catch that — it bails on
    // a terminal attempt.
    $attempt = MediaReplacementAttempt::factory()->create([
        'action_request_id' => $actionRequest->id,
        'service_connection_id' => ServiceConnection::query()->firstOrFail()->id,
        'status' => MediaReplacementStatus::NeedsAttention,
        'failure_reason' => 'restore_monitoring_failed',
        'grab_accepted_at' => now()->subDays(3),
        'cleanup_completed_at' => null,
        'started_at' => now()->subDays(3),
        'completed_at' => now()->subDays(3),
        'was_monitored' => true,
        'monitoring_suspended' => true,
        'target' => [
            'service' => 'sonarr', 'scope' => 'anime', 'series_id' => 42,
            'episode_ids' => [101], 'episode_file_ids' => [501],
            'installed_release' => 'Trusted.Anime.S01E01.OLD', 'original_history_id' => 999,
        ],
    ]);

    resolve(MediaReplacementActions::class)->execute($actionRequest);

    // Still terminal, so the row still matches the repair pass's status predicate.
    expect($attempt->fresh()->status->isTerminal())->toBeTrue();

    // Re-faking resets the recorded requests, so the assertions below see only what
    // the reconciliation run itself sent.
    fakeExecutor();

    $this->artisan('media-replacement:reconcile')->assertSuccessful();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT'
        && str_contains($request->url(), '/api/v3/episode/monitor')
        && $request->data()['monitored'] === true);

    // The suspension is still owed, and still durable: the repair pass picks it up
    // once the resumed run's own window has elapsed.
    expect($attempt->fresh()->monitoring_suspended)->toBeTrue();
});

test('a rejected grab still restores monitoring immediately because no blocklist ran', function (): void {
    fakeExecutor(['grabOk' => false, 'grabStatus' => 400, 'monitored' => true]);

    $actionRequest = replaceActionRequest();

    expect(fn (): array => resolve(MediaReplacementActions::class)->execute($actionRequest))
        ->toThrow(RuntimeException::class);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && str_contains($request->url(), '/api/v3/episode/monitor')
        && $request->data()['monitored'] === true);

    // The restore is recorded, so the reconciliation repair pass does not later
    // re-issue a monitor PUT for a suspension that no longer exists.
    expect(MediaReplacementAttempt::query()->where('action_request_id', $actionRequest->id)->sole()->monitoring_suspended)
        ->toBeFalse();
});
