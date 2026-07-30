<?php

declare(strict_types=1);

use App\Enums\MediaReplacementStatus;
use App\Events\MediaReplacementAttemptChanged;
use App\Models\ActionRequest;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Services\Actions\SharedMediaTargetLock;
use App\Services\MediaReplacement\MediaReplacementActions;
use App\Services\MediaReplacement\MediaReplacementTracker;
use App\Services\MediaReplacement\ReleaseFingerprint;
use App\Settings\MediaReplacementSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
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
 * @param  array{grabOk?: bool, grabConnection?: bool, grabStatus?: int, deleteOk?: bool, monitorOk?: bool, monitored?: bool, currentFileId?: int, subtitles?: string, releases?: list<array<string, mixed>>, onDelete?: callable}  $opts
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
    $onDelete = $opts['onDelete'] ?? null;

    Http::fake(function (Request $request) use ($grabConnection, $grabStatus, $deleteStatus, $monitorOk, $monitored, $currentFileId, $subtitles, $releases, $onDelete) {
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

test('rejects execution while a Bazarr subtitle operation holds the shared installed-file lock', function (): void {
    fakeExecutor();
    $connectionId = ServiceConnection::query()->firstOrFail()->id;

    // A Bazarr subtitle download/delete/sync executor has claimed the same
    // installed episode file. The destructive replacement must refuse to run.
    $lock = Cache::lock(SharedMediaTargetLock::key($connectionId, 'episode', 101), 120);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn (): array => resolve(MediaReplacementActions::class)->execute(replaceActionRequest()))
            ->toThrow(RuntimeException::class, 'locked by another operation');

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/api/v3/release'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    } finally {
        $lock->release();
    }
});

test('aborts a destructive replacement when the pinned connection was deactivated after approval', function (): void {
    fakeExecutor();
    $connection = ServiceConnection::query()->firstOrFail();
    $connection->update(['is_active' => false]);

    $fingerprint = (new ReleaseFingerprint)->make('sonarr', sonarrReplacementRelease());
    $actionRequest = ActionRequest::factory()->create([
        'type' => 'replace_media_file', 'source_service' => 'ai', 'target_service' => 'sonarr',
        'payload' => [
            'service' => 'sonarr',
            'service_connection_id' => $connection->id,
            'scope' => 'anime',
            'target' => [
                'service' => 'sonarr', 'service_connection_id' => $connection->id, 'scope' => 'anime',
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

test('a resume with a durably-failed suspension does not blocklist (independent of failure_reason)', function (): void {
    fakeExecutor();
    $actionRequest = replaceActionRequest();

    // Prior run: monitoring suspension FAILED (monitoring_suspended=false) AND
    // then deletion failed (failure_reason='deletion_failed', the resumable
    // marker). The resume must still skip the blocklist — driven by the durable
    // monitoring_suspended=false, NOT by parsing the failure_reason.
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

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/api/v3/history/failed/'));
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

    // The executor finalized the pending verification to needs_attention: the
    // fixture file still has only Japanese subtitles vs required English. Monitoring
    // was restored by the executor (not the tracker, which deferred it), so the sole
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

    // The only remonitor is the executor's own, AFTER blocklisting; the fast
    // webhook did not remonitor because the stale checkpoint was reset.
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

    // Once cleanup completed and monitoring was restored, the executor finalized the
    // pending verification to Verified with no false restore-failure reason.
    expect(MediaReplacementAttempt::first()->status)->toBe(MediaReplacementStatus::Verified)
        ->and(MediaReplacementAttempt::first()->failure_reason)->toBeNull()
        ->and($result['status'])->toBe('verified');

    // The blocklist ran (target stayed suspended through cleanup) and was safe.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '/api/v3/history/failed/'));
});
