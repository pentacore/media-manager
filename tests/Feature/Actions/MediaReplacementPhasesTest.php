<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Enums\MediaReplacementStatus;
use App\Events\MediaReplacementAttemptChanged;
use App\Jobs\Ai\GenerateConversationTitle;
use App\Jobs\AuditImportedSubtitles;
use App\Jobs\EmbedLibraryItem;
use App\Jobs\ExecuteActionRequest;
use App\Jobs\FetchLatestServiceVersion;
use App\Jobs\PingServiceHealth;
use App\Jobs\SweepCompetingGrabs;
use App\Models\ActionRequest;
use App\Models\ActivityLog;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Services\Actions\SharedMediaTargetLock;
use App\Services\MediaReplacement\MediaReplacementActions;
use App\Services\MediaReplacement\MediaReplacementExecutionLock;
use App\Services\MediaReplacement\ReleaseFingerprint;
use App\Settings\MediaReplacementSettings;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();
    // tests/Pest.php's global beforeEach already fakes PingServiceHealth,
    // FetchLatestServiceVersion, GenerateConversationTitle, EmbedLibraryItem,
    // ExecuteActionRequest and AuditImportedSubtitles so the ServiceConnection
    // observer's sync-queue jobs don't make real GitHub/health HTTP calls
    // during factory creation. Queue::fake() replaces the whole fake set
    // rather than merging into it, so this file's own Queue::fake call must
    // repeat that list alongside SweepCompetingGrabs — observed by running
    // this suite against today's code: without the full list, creating the
    // Sonarr/Radarr ServiceConnection dispatches a real FetchLatestServiceVersion
    // job that hits https://api.github.com and throws StrayRequestException.
    Queue::fake([
        PingServiceHealth::class,
        FetchLatestServiceVersion::class,
        GenerateConversationTitle::class,
        EmbedLibraryItem::class,
        ExecuteActionRequest::class,
        AuditImportedSubtitles::class,
        SweepCompetingGrabs::class,
    ]);

    $confirmingRule = static fn (string $name): array => [[
        'name' => $name, 'enabled' => true, 'strength' => 'guarantee', 'languages' => ['English'],
        'conditions' => [['field' => 'title', 'value' => 'CR']],
    ]];

    resolve(MediaReplacementSettings::class)->setConfiguration([
        'automatic_selection_enabled' => false,
        'automatic_selection_threshold' => 90,
        'global_languages' => ['English'],
        'scoped_languages' => ['anime' => null, 'tv' => null, 'movie' => null],
        'season_pack_policy' => 'approval_required',
        'guidance' => [
            'anime' => ['notes' => '', 'rules' => $confirmingRule('CR')],
            'tv' => ['notes' => '', 'rules' => []],
            'movie' => ['notes' => '', 'rules' => $confirmingRule('Trusted')],
        ],
    ]);
});

function replacementPhasesSonarr(): ServiceConnection
{
    return ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
    ]);
}

function replacementPhasesRadarr(): ServiceConnection
{
    return ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878', 'api_key' => 'test', 'is_active' => true,
    ]);
}

/**
 * @return array<string, mixed>
 */
function replacementPhasesSonarrRelease(): array
{
    return [
        'guid' => 'g1', 'indexerId' => 10, 'title' => 'Trusted.Anime.S01E01.CR',
        'episodeIds' => [101], 'downloadAllowed' => true, 'rejections' => [], 'fullSeason' => false,
        'customFormatScore' => 0, 'qualityWeight' => 100, 'seeders' => 5, 'ageMinutes' => 60,
        'downloadUrl' => 'http://sonarr.local/download/g1',
    ];
}

/**
 * @return array<string, mixed>
 */
function replacementPhasesRadarrRelease(): array
{
    return [
        'guid' => 'guid-1', 'indexerId' => 10, 'title' => 'A.Movie.2026.CR.1080p.BluRay',
        'releaseGroup' => 'GROUP', 'movieId' => 7, 'episodeIds' => [], 'downloadAllowed' => true,
        'rejections' => [], 'fullSeason' => false, 'customFormats' => [], 'customFormatScore' => 10,
        'qualityWeight' => 100, 'seeders' => 5, 'ageMinutes' => 60,
        'downloadUrl' => 'http://radarr.local/download/guid-1',
    ];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function replacementPhasesSonarrRequest(ServiceConnection $sonarr, array $attributes = []): ActionRequest
{
    $fingerprint = (new ReleaseFingerprint)->make('sonarr', replacementPhasesSonarrRelease());

    return ActionRequest::factory()->create([
        'type' => 'replace_media_file',
        'source_service' => 'ai',
        'target_service' => 'sonarr',
        'payload' => [
            'service' => 'sonarr',
            'service_connection_id' => $sonarr->id,
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
        ...$attributes,
    ]);
}

function replacementPhasesRadarrRequest(ServiceConnection $radarr): ActionRequest
{
    $fingerprint = (new ReleaseFingerprint)->make('radarr', replacementPhasesRadarrRelease());

    return ActionRequest::factory()->create([
        'type' => 'replace_media_file',
        'source_service' => 'ai',
        'target_service' => 'radarr',
        'payload' => [
            'service' => 'radarr',
            'service_connection_id' => $radarr->id,
            'scope' => 'movie',
            'target' => [
                'service' => 'radarr', 'scope' => 'movie', 'movie_id' => 7,
                'movie_file_ids' => [701], 'installed_release' => 'A.Movie.2026.1080p.BluRay',
                'original_history_id' => 888,
            ],
            'candidate_fingerprint' => $fingerprint,
            'candidate' => ['fingerprint' => $fingerprint, 'title' => 'A.Movie.2026.CR.1080p.BluRay', 'confidence' => 98],
            'required_languages' => ['eng'],
            'selection_mode' => 'manual',
            'original_history_id' => 888,
        ],
    ]);
}

/**
 * Host-scoped fakes for both arrs. Every request is appended to $trace->calls
 * as "METHOD /path". Options: grab (accepted|rejected|indeterminate),
 * currentFileId (int), releases (list), deleteStatus (int), lockKey (string:
 * recorded into $trace->sharedLockFreeDuringGrab at the grab POST).
 *
 * @param  array<string, mixed>  $options
 */
function replacementPhasesFakeArrs(stdClass $trace, array $options = []): void
{
    $trace->calls = [];
    $trace->sharedLockFreeDuringGrab = null;

    Http::fake([
        'sonarr.local:8989/*' => static fn (Request $request): PromiseInterface => replacementPhasesSonarrResponse($request, $trace, $options),
        'radarr.local:7878/*' => static fn (Request $request): PromiseInterface => replacementPhasesRadarrResponse($request, $trace, $options),
    ]);
}

/**
 * @param  array<string, mixed>  $options
 */
function replacementPhasesRecord(Request $request, stdClass $trace, array $options): string
{
    $call = sprintf('%s %s', $request->method(), (string) parse_url($request->url(), PHP_URL_PATH));
    $trace->calls[] = $call;

    if ($call === 'POST /api/v3/release' && is_string($options['lockKey'] ?? null)) {
        $lock = Cache::lock($options['lockKey'], 1);
        $trace->sharedLockFreeDuringGrab = $lock->get();

        if ($trace->sharedLockFreeDuringGrab) {
            $lock->release();
        }
    }

    return $call;
}

/**
 * @param  array<string, mixed>  $options
 */
function replacementPhasesGrabStatus(array $options): int
{
    return match ($options['grab'] ?? 'accepted') {
        'rejected' => 400,
        'indeterminate' => 500,
        default => 201,
    };
}

/**
 * @param  array<string, mixed>  $options
 */
function replacementPhasesSonarrResponse(Request $request, stdClass $trace, array $options): PromiseInterface
{
    $call = replacementPhasesRecord($request, $trace, $options);
    $currentFileId = (int) ($options['currentFileId'] ?? 501);

    return match (true) {
        $call === 'POST /api/v3/release' => Http::response([], replacementPhasesGrabStatus($options)),
        $call === 'GET /api/v3/release' => Http::response($options['releases'] ?? [replacementPhasesSonarrRelease()]),
        $call === 'PUT /api/v3/episode/monitor' => Http::response([], 200),
        str_starts_with($call, 'DELETE /api/v3/episodefile/') => Http::response([], (int) ($options['deleteStatus'] ?? 200)),
        $call === 'GET /api/v3/series/42' => Http::response(['id' => 42, 'title' => 'Trusted Anime', 'seriesType' => 'anime']),
        $call === 'GET /api/v3/episode' => Http::response([
            ['id' => 101, 'seasonNumber' => 1, 'episodeNumber' => 1, 'episodeFileId' => $currentFileId, 'monitored' => true],
        ]),
        str_starts_with($call, 'GET /api/v3/episodefile/') => Http::response([
            'id' => $currentFileId,
            'sceneName' => $currentFileId === 501 ? 'Trusted.Anime.S01E01.OLD' : 'DIFFERENT',
            'mediaInfo' => ['subtitles' => 'Japanese'],
        ]),
        str_starts_with($call, 'POST /api/v3/history/failed/') => Http::response([], 200),
        $call === 'GET /api/v3/history' => Http::response(['records' => [
            ['id' => 999, 'eventType' => 'grabbed', 'episodeId' => 101],
        ]]),
        $call === 'GET /api/v3/queue' => Http::response(['records' => []]),
        default => Http::response(['unexpected' => $call], 418),
    };
}

/**
 * @param  array<string, mixed>  $options
 */
function replacementPhasesRadarrResponse(Request $request, stdClass $trace, array $options): PromiseInterface
{
    $call = replacementPhasesRecord($request, $trace, $options);

    return match (true) {
        $call === 'POST /api/v3/release' => Http::response([], replacementPhasesGrabStatus($options)),
        $call === 'GET /api/v3/release' => Http::response($options['releases'] ?? [replacementPhasesRadarrRelease()]),
        $call === 'PUT /api/v3/movie/editor' => Http::response([], 200),
        $call === 'DELETE /api/v3/moviefile/701' => Http::response([], (int) ($options['deleteStatus'] ?? 200)),
        $call === 'GET /api/v3/movie/7' => Http::response(['id' => 7, 'title' => 'A Movie', 'movieFileId' => 701, 'monitored' => true]),
        $call === 'GET /api/v3/moviefile/701' => Http::response([
            'id' => 701,
            'movieId' => 7,
            'sceneName' => 'A.Movie.2026.1080p.BluRay',
            'releaseGroup' => 'GROUP',
            'quality' => ['quality' => ['name' => 'Bluray-1080p']],
            'mediaInfo' => ['subtitles' => 'Japanese'],
        ]),
        $call === 'POST /api/v3/history/failed/888' => Http::response([], 200),
        $call === 'GET /api/v3/history' => Http::response(['records' => [
            ['id' => 888, 'eventType' => 'grabbed', 'movieId' => 7],
        ]]),
        $call === 'GET /api/v3/queue' => Http::response(['records' => []]),
        default => Http::response(['unexpected' => $call], 418),
    };
}

/**
 * @return list<array{action: string, description: string}>
 */
function replacementPhasesStatusRows(ActionRequest $actionRequest): array
{
    return ActivityLog::query()
        ->where('subject_type', ActionRequest::class)
        ->where('subject_id', $actionRequest->id)
        ->whereIn('action', ['action_request.executing', 'action_request.completed', 'action_request.failed'])
        ->orderBy('id')
        ->get()
        ->map(static fn (ActivityLog $activityLog): array => ['action' => $activityLog->action, 'description' => $activityLog->description])
        ->values()
        ->all();
}

test('a fresh Sonarr replacement talks to Sonarr in this exact order and reports this exact result', function (): void {
    $serviceConnection = replacementPhasesSonarr();
    $actionRequest = replacementPhasesSonarrRequest($serviceConnection);
    $trace = new stdClass;
    replacementPhasesFakeArrs($trace, ['lockKey' => SharedMediaTargetLock::key($serviceConnection->id, 'episode', 101)]);

    $result = resolve(MediaReplacementActions::class)->execute($actionRequest);
    $mediaReplacementAttempt = MediaReplacementAttempt::query()->where('action_request_id', $actionRequest->id)->sole();

    expect($trace->calls)->toBe([
        'GET /api/v3/series/42',
        'GET /api/v3/episode',
        'GET /api/v3/episodefile/501',
        'GET /api/v3/history',
        'GET /api/v3/release',
        'PUT /api/v3/episode/monitor',
        'POST /api/v3/release',
        'DELETE /api/v3/episodefile/501',
        'POST /api/v3/history/failed/999',
    ])
        ->and($trace->sharedLockFreeDuringGrab)->toBeFalse()
        ->and($result)->toBe([
            'attempt_id' => $mediaReplacementAttempt->id,
            'status' => 'downloading',
            'replacement_initiated' => true,
            'deleted_files' => 1,
            'blocklist_warning' => null,
            'competing_grabs_removed' => 0,
        ])
        ->and($mediaReplacementAttempt->status)->toBe(MediaReplacementStatus::Downloading)
        ->and($mediaReplacementAttempt->service_connection_id)->toBe($serviceConnection->id)
        ->and($mediaReplacementAttempt->scope)->toBe('anime')
        ->and($mediaReplacementAttempt->candidate_fingerprint)->toBe($actionRequest->payload['candidate_fingerprint'])
        ->and($mediaReplacementAttempt->required_languages)->toBe(['eng'])
        ->and($mediaReplacementAttempt->target['episode_file_ids'])->toBe([501])
        ->and($mediaReplacementAttempt->was_monitored)->toBeTrue()
        ->and($mediaReplacementAttempt->monitoring_suspended)->toBeTrue()
        ->and($mediaReplacementAttempt->download_id)->toBeNull()
        ->and($mediaReplacementAttempt->failure_reason)->toBeNull()
        ->and($mediaReplacementAttempt->grab_attempted_at)->not->toBeNull()
        ->and($mediaReplacementAttempt->grab_accepted_at)->not->toBeNull()
        ->and($mediaReplacementAttempt->cleanup_completed_at)->not->toBeNull()
        ->and($mediaReplacementAttempt->completed_at)->toBeNull();

    Queue::assertPushed(SweepCompetingGrabs::class, 1);
});

test('a fresh Radarr replacement talks to Radarr in this exact order and reports this exact result', function (): void {
    $serviceConnection = replacementPhasesRadarr();
    $actionRequest = replacementPhasesRadarrRequest($serviceConnection);
    $trace = new stdClass;
    replacementPhasesFakeArrs($trace, ['lockKey' => SharedMediaTargetLock::key($serviceConnection->id, 'movie', 7)]);

    $result = resolve(MediaReplacementActions::class)->execute($actionRequest);
    $mediaReplacementAttempt = MediaReplacementAttempt::query()->where('action_request_id', $actionRequest->id)->sole();

    expect($trace->calls)->toBe([
        'GET /api/v3/movie/7',
        'GET /api/v3/moviefile/701',
        'GET /api/v3/history',
        'GET /api/v3/release',
        'PUT /api/v3/movie/editor',
        'POST /api/v3/release',
        'DELETE /api/v3/moviefile/701',
        'POST /api/v3/history/failed/888',
    ])
        ->and($trace->sharedLockFreeDuringGrab)->toBeFalse()
        ->and($result)->toBe([
            'attempt_id' => $mediaReplacementAttempt->id,
            'status' => 'downloading',
            'replacement_initiated' => true,
            'deleted_files' => 1,
            'blocklist_warning' => null,
            'competing_grabs_removed' => 0,
        ])
        ->and($mediaReplacementAttempt->scope)->toBe('movie')
        ->and($mediaReplacementAttempt->target['movie_file_ids'])->toBe([701])
        ->and($mediaReplacementAttempt->was_monitored)->toBeTrue()
        ->and($mediaReplacementAttempt->monitoring_suspended)->toBeTrue()
        ->and($mediaReplacementAttempt->cleanup_completed_at)->not->toBeNull();
});

test('a rejected grab restores monitoring, fails the attempt once and touches no file', function (): void {
    Event::fake([MediaReplacementAttemptChanged::class]);
    $serviceConnection = replacementPhasesSonarr();
    $actionRequest = replacementPhasesSonarrRequest($serviceConnection);
    $trace = new stdClass;
    replacementPhasesFakeArrs($trace, ['grab' => 'rejected']);

    expect(fn (): array => resolve(MediaReplacementActions::class)->execute($actionRequest))
        ->toThrow(RuntimeException::class, 'Replacement grab was rejected.');

    $mediaReplacementAttempt = MediaReplacementAttempt::query()->where('action_request_id', $actionRequest->id)->sole();

    expect($trace->calls)->toBe([
        'GET /api/v3/series/42',
        'GET /api/v3/episode',
        'GET /api/v3/episodefile/501',
        'GET /api/v3/history',
        'GET /api/v3/release',
        'PUT /api/v3/episode/monitor',
        'POST /api/v3/release',
        'PUT /api/v3/episode/monitor',
    ])
        ->and($mediaReplacementAttempt->status)->toBe(MediaReplacementStatus::Failed)
        ->and($mediaReplacementAttempt->failure_reason)->toBe('Replacement grab was rejected; the current file was left untouched.')
        ->and($mediaReplacementAttempt->monitoring_suspended)->toBeFalse()
        ->and($mediaReplacementAttempt->grab_accepted_at)->toBeNull()
        ->and($mediaReplacementAttempt->completed_at)->not->toBeNull();

    Event::assertDispatchedTimes(MediaReplacementAttemptChanged::class, 1);
    Queue::assertNotPushed(SweepCompetingGrabs::class);
});

test('an indeterminate grab leaves the attempt trackable with this exact result', function (): void {
    $serviceConnection = replacementPhasesSonarr();
    $actionRequest = replacementPhasesSonarrRequest($serviceConnection);
    $trace = new stdClass;
    replacementPhasesFakeArrs($trace, ['grab' => 'indeterminate']);

    $result = resolve(MediaReplacementActions::class)->execute($actionRequest);
    $mediaReplacementAttempt = MediaReplacementAttempt::query()->where('action_request_id', $actionRequest->id)->sole();

    expect($trace->calls)->toBe([
        'GET /api/v3/series/42',
        'GET /api/v3/episode',
        'GET /api/v3/episodefile/501',
        'GET /api/v3/history',
        'GET /api/v3/release',
        'PUT /api/v3/episode/monitor',
        'POST /api/v3/release',
    ])
        ->and($result)->toBe([
            'attempt_id' => $mediaReplacementAttempt->id,
            'status' => 'downloading',
            'replacement_initiated' => false,
            'grab_outcome' => 'indeterminate',
            'deleted_files' => 0,
            'message' => 'Grab outcome was indeterminate; tracking it via webhooks and reconciliation.',
        ])
        ->and($mediaReplacementAttempt->status)->toBe(MediaReplacementStatus::Downloading)
        ->and($mediaReplacementAttempt->grab_attempted_at)->not->toBeNull()
        ->and($mediaReplacementAttempt->grab_accepted_at)->toBeNull()
        ->and($mediaReplacementAttempt->cleanup_completed_at)->not->toBeNull()
        ->and($mediaReplacementAttempt->monitoring_suspended)->toBeTrue();

    Queue::assertNotPushed(SweepCompetingGrabs::class);
});

test('a resumed accepted grab re-asserts the suspension, deletes and blocklists without searching or grabbing', function (): void {
    $serviceConnection = replacementPhasesSonarr();
    $actionRequest = replacementPhasesSonarrRequest($serviceConnection);
    $attempt = MediaReplacementAttempt::factory()->create([
        'action_request_id' => $actionRequest->id,
        'service_connection_id' => $serviceConnection->id,
        'status' => MediaReplacementStatus::Downloading,
        'grab_attempted_at' => now()->subMinute(),
        'grab_accepted_at' => now()->subMinute(),
        'cleanup_completed_at' => null,
        'was_monitored' => true,
        'monitoring_suspended' => true,
        'target' => $actionRequest->payload['target'],
    ]);
    $trace = new stdClass;
    replacementPhasesFakeArrs($trace);

    $result = resolve(MediaReplacementActions::class)->execute($actionRequest);

    expect($trace->calls)->toBe([
        'PUT /api/v3/episode/monitor',
        'DELETE /api/v3/episodefile/501',
        'POST /api/v3/history/failed/999',
    ])
        ->and($result)->toBe([
            'attempt_id' => $attempt->id,
            'status' => 'downloading',
            'replacement_initiated' => true,
            'deleted_files' => 1,
            'blocklist_warning' => null,
            'competing_grabs_removed' => 0,
        ])
        ->and($attempt->fresh()->cleanup_completed_at)->not->toBeNull()
        ->and($attempt->fresh()->monitoring_suspended)->toBeTrue();
});

test('a prior grab whose outcome was never recorded is left to tracking without any request', function (): void {
    $serviceConnection = replacementPhasesSonarr();
    $actionRequest = replacementPhasesSonarrRequest($serviceConnection);
    $attempt = MediaReplacementAttempt::factory()->create([
        'action_request_id' => $actionRequest->id,
        'service_connection_id' => $serviceConnection->id,
        'status' => MediaReplacementStatus::Downloading,
        'grab_attempted_at' => now()->subMinute(),
        'grab_accepted_at' => null,
        'cleanup_completed_at' => null,
    ]);
    $trace = new stdClass;
    replacementPhasesFakeArrs($trace);

    $result = resolve(MediaReplacementActions::class)->execute($actionRequest);

    expect($trace->calls)->toBe([])
        ->and($result)->toBe([
            'attempt_id' => $attempt->id,
            'status' => 'downloading',
            'replacement_initiated' => false,
            'grab_outcome' => 'indeterminate',
            'deleted_files' => 0,
            'message' => 'A previous run attempted the grab but its outcome was never recorded; not re-grabbing. Webhooks and reconciliation will resolve it.',
        ])
        ->and($attempt->fresh()->cleanup_completed_at)->not->toBeNull();
});

test('an accepted grab whose cleanup already completed is reported as resolved without any request', function (): void {
    $serviceConnection = replacementPhasesSonarr();
    $actionRequest = replacementPhasesSonarrRequest($serviceConnection);
    $attempt = MediaReplacementAttempt::factory()->create([
        'action_request_id' => $actionRequest->id,
        'service_connection_id' => $serviceConnection->id,
        'status' => MediaReplacementStatus::Verified,
        'grab_attempted_at' => now()->subHour(),
        'grab_accepted_at' => now()->subHour(),
        'cleanup_completed_at' => now()->subMinutes(50),
    ]);
    $trace = new stdClass;
    replacementPhasesFakeArrs($trace);

    $result = resolve(MediaReplacementActions::class)->execute($actionRequest);

    expect($trace->calls)->toBe([])
        ->and($result)->toBe([
            'attempt_id' => $attempt->id,
            'status' => 'verified',
            'replacement_initiated' => false,
            'grab_outcome' => 'already_resolved',
            'deleted_files' => 0,
            'message' => 'The grab was already accepted and cleanup completed; not re-grabbing or reopening.',
        ]);
});

test('an abort before the grab names its reason, stops its requests at the failed check and claims nothing', function (array $options, string $message, array $calls): void {
    $serviceConnection = replacementPhasesSonarr();
    $actionRequest = replacementPhasesSonarrRequest($serviceConnection);
    $trace = new stdClass;
    replacementPhasesFakeArrs($trace, $options);

    expect(fn (): array => resolve(MediaReplacementActions::class)->execute($actionRequest))
        ->toThrow(InvalidArgumentException::class, $message);

    expect($trace->calls)->toBe($calls)
        ->and(MediaReplacementAttempt::query()->where('action_request_id', $actionRequest->id)->exists())->toBeFalse();
})->with([
    'installed file changed' => [
        ['currentFileId' => 777],
        'Installed media files changed after approval; aborting replacement.',
        ['GET /api/v3/series/42', 'GET /api/v3/episode', 'GET /api/v3/episodefile/777', 'GET /api/v3/history'],
    ],
    'release no longer offered' => [
        ['releases' => []],
        'Selected release is no longer eligible.',
        ['GET /api/v3/series/42', 'GET /api/v3/episode', 'GET /api/v3/episodefile/501', 'GET /api/v3/history', 'GET /api/v3/release'],
    ],
]);

test('a failed delete after an accepted grab marks the attempt for attention with the exact reason', function (): void {
    $serviceConnection = replacementPhasesSonarr();
    $actionRequest = replacementPhasesSonarrRequest($serviceConnection);
    $trace = new stdClass;
    replacementPhasesFakeArrs($trace, ['deleteStatus' => 500]);

    expect(fn (): array => resolve(MediaReplacementActions::class)->execute($actionRequest))
        ->toThrow(RuntimeException::class, 'Replacement grabbed but deletion of the reviewed file failed.');

    $mediaReplacementAttempt = MediaReplacementAttempt::query()->where('action_request_id', $actionRequest->id)->sole();

    expect($mediaReplacementAttempt->status)->toBe(MediaReplacementStatus::NeedsAttention)
        ->and($mediaReplacementAttempt->failure_reason)->toBe('deletion_failed')
        ->and($mediaReplacementAttempt->grab_accepted_at)->not->toBeNull()
        ->and($mediaReplacementAttempt->cleanup_completed_at)->toBeNull()
        ->and($trace->calls)->not->toContain('POST /api/v3/history/failed/999');
});

test('both locks are free again after every way out of a replacement', function (array $options, bool $throws): void {
    $serviceConnection = replacementPhasesSonarr();
    $actionRequest = replacementPhasesSonarrRequest($serviceConnection);
    $trace = new stdClass;
    replacementPhasesFakeArrs($trace, $options);

    $run = fn (): array => resolve(MediaReplacementActions::class)->execute($actionRequest);

    if ($throws) {
        expect($run)->toThrow(Exception::class);
    } else {
        $run();
    }

    $sharedLock = Cache::lock(SharedMediaTargetLock::key($serviceConnection->id, 'episode', 101), 1);
    $executionLock = Cache::lock(MediaReplacementExecutionLock::key($actionRequest->id), 1);

    expect($sharedLock->get())->toBeTrue()
        ->and($executionLock->get())->toBeTrue();
})->with([
    'success' => [[], false],
    'abort before the grab' => [['releases' => []], true],
    'rejected grab' => [['grab' => 'rejected'], true],
    'indeterminate grab' => [['grab' => 'indeterminate'], false],
    'deletion failed' => [['deleteStatus' => 500], true],
]);

test('through the job a replacement completes the request with the executor result and two status rows', function (): void {
    $serviceConnection = replacementPhasesSonarr();
    $actionRequest = replacementPhasesSonarrRequest($serviceConnection, ['status' => ActionRequestStatus::Approved]);
    $trace = new stdClass;
    replacementPhasesFakeArrs($trace);

    new ExecuteActionRequest($actionRequest)->handle();

    $actionRequest->refresh();
    $mediaReplacementAttempt = MediaReplacementAttempt::query()->where('action_request_id', $actionRequest->id)->sole();

    expect($actionRequest->status)->toBe(ActionRequestStatus::Completed)
        ->and($actionRequest->result)->toBe([
            'success' => true,
            'attempt_id' => $mediaReplacementAttempt->id,
            'status' => 'downloading',
            'replacement_initiated' => true,
            'deleted_files' => 1,
            'blocklist_warning' => null,
            'competing_grabs_removed' => 0,
        ])
        ->and(replacementPhasesStatusRows($actionRequest))->toBe([
            ['action' => 'action_request.executing', 'description' => sprintf('Action #%d started', $actionRequest->id)],
            ['action' => 'action_request.completed', 'description' => sprintf('Action #%d completed', $actionRequest->id)],
        ]);
});

test('through the job an abort fails the request with the exact reason and two status rows', function (): void {
    $serviceConnection = replacementPhasesSonarr();
    $actionRequest = replacementPhasesSonarrRequest($serviceConnection, ['status' => ActionRequestStatus::Approved]);
    $trace = new stdClass;
    replacementPhasesFakeArrs($trace, ['currentFileId' => 777]);

    new ExecuteActionRequest($actionRequest)->handle();

    $actionRequest->refresh();

    expect($actionRequest->status)->toBe(ActionRequestStatus::Failed)
        ->and($actionRequest->result)->toBe([
            'success' => false,
            'reason' => 'execution_failed',
            'message' => 'Installed media files changed after approval; aborting replacement.',
            'exception' => InvalidArgumentException::class,
            'indeterminate' => false,
        ])
        ->and(replacementPhasesStatusRows($actionRequest))->toBe([
            ['action' => 'action_request.executing', 'description' => sprintf('Action #%d started', $actionRequest->id)],
            ['action' => 'action_request.failed', 'description' => sprintf('Action #%d failed: execution_failed', $actionRequest->id)],
        ]);
});

test('one escalation runs exactly one release search', function (string $service): void {
    $connection = $service === 'sonarr' ? replacementPhasesSonarr() : replacementPhasesRadarr();
    $actionRequest = $service === 'sonarr'
        ? replacementPhasesSonarrRequest($connection)
        : replacementPhasesRadarrRequest($connection);
    $trace = new stdClass;
    replacementPhasesFakeArrs($trace);

    $result = resolve(MediaReplacementActions::class)->execute($actionRequest);

    $releaseSearches = array_values(array_filter(
        $trace->calls,
        static fn (string $call): bool => $call === 'GET /api/v3/release',
    ));

    expect($releaseSearches)->toHaveCount(1)
        ->and($result['replacement_initiated'])->toBeTrue();
})->with(['sonarr', 'radarr']);
