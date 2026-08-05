<?php

declare(strict_types=1);

use App\Cache\Services\SonarrCache;
use App\Enums\MediaReplacementStatus;
use App\Enums\UserRole;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Notifications\MediaReplacementStatusChanged;
use App\Services\MediaReplacement\ImportedSubtitleAuditor;
use App\Settings\MediaReplacementSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();
    Notification::fake();

    $this->admin = User::factory()->create(['role' => UserRole::Admin]);

    // requires_approval is false so a forced-approval assertion elsewhere in
    // this file can only be true because the auditor tightened the gate.
    ActionTypeConfig::factory()->create([
        'type' => 'replace_media_file',
        'requires_approval' => false,
        'is_enabled' => true,
    ]);

    $this->connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
        'settings' => [
            'subtitle_check_tags' => ['sub-check'],
            'sonarr_root_folders' => [['root_folder_id' => 1, 'path' => '/tv', 'scope' => 'tv']],
        ],
    ]);

    resolve(MediaReplacementSettings::class)->setConfiguration([
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
                    // strong_evidence scores 85, below the 90 threshold, so a WEB
                    // release is shortlisted but never automatically selectable.
                    [
                        'name' => 'WEB', 'enabled' => true, 'strength' => 'strong_evidence', 'languages' => ['English'],
                        'conditions' => [['field' => 'title', 'value' => 'WEB']],
                    ],
                ],
            ],
            'movie' => ['notes' => '', 'rules' => []],
        ],
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function importPayload(array $overrides = []): array
{
    return array_replace([
        'eventType' => 'Download',
        'series' => ['id' => 42, 'title' => 'Tagged Show'],
        'episodes' => [['id' => 101, 'seasonNumber' => 1, 'episodeNumber' => 1]],
        'episodeFile' => ['id' => 501],
        'downloadId' => 'DL-ORGANIC',
    ], $overrides);
}

/**
 * Fakes the arr surface the auditor touches. `subtitles` drives the imported
 * file's mediaInfo, `tagIds` the series' tags, `tags` the instance's tag rows,
 * `episodes` the series' episode list and `releases` the search result.
 *
 * @param  array{subtitles?: string, tagIds?: list<int>, tags?: list<array<string, mixed>>, episodes?: list<array<string, mixed>>, releases?: list<array<string, mixed>>}  $opts
 */
function fakeAuditArr(array $opts = []): void
{
    $subtitles = $opts['subtitles'] ?? 'Japanese';
    $tagIds = $opts['tagIds'] ?? [1];
    $tags = $opts['tags'] ?? [
        ['id' => 1, 'label' => 'sub-check'],
        ['id' => 2, 'label' => 'other'],
    ];
    $episodes = $opts['episodes'] ?? [[
        'id' => 101, 'seriesId' => 42, 'seasonNumber' => 1, 'episodeNumber' => 1,
        'episodeFileId' => 501, 'monitored' => true, 'hasFile' => true, 'title' => 'Ep 1',
    ]];
    $releases = $opts['releases'] ?? [[
        'guid' => 'g1', 'indexerId' => 10, 'title' => 'Tagged.Show.S01E01.CR',
        'episodeIds' => [101], 'downloadAllowed' => true, 'rejections' => [], 'fullSeason' => false,
        'customFormatScore' => 0, 'qualityWeight' => 100, 'seeders' => 5, 'ageMinutes' => 60,
        'downloadUrl' => 'http://sonarr.local/download/g1',
    ]];

    Http::fake([
        'sonarr.local:8989/api/v3/tag' => Http::response($tags),
        'sonarr.local:8989/api/v3/series/42' => Http::response([
            'id' => 42, 'title' => 'Tagged Show', 'path' => '/tv/Tagged Show',
            'monitored' => true, 'tags' => $tagIds, 'seriesType' => 'standard',
        ]),
        // episodefile must precede the episode wildcard: Http::fake matches in
        // declaration order and `episode*` also matches `episodefile/501`.
        'sonarr.local:8989/api/v3/episodefile/501' => Http::response([
            'id' => 501, 'seriesId' => 42, 'quality' => ['quality' => ['name' => 'WEBDL-1080p']],
            'size' => 100, 'sceneName' => 'Tagged.Show.S01E01.OLD', 'releaseGroup' => 'OLD',
            'mediaInfo' => ['subtitles' => $subtitles],
        ]),
        'sonarr.local:8989/api/v3/episode*' => Http::response($episodes),
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => [[
            'id' => 9001, 'eventType' => 'downloadFolderImported', 'episodeId' => 101, 'episodeFileId' => 501,
        ]]]),
        'sonarr.local:8989/api/v3/release*' => Http::response($releases),
        'sonarr.local:8989/api/v3/rootfolder' => Http::response([]),
    ]);
}

test('a tagged import missing a required language dispatches a replacement', function (): void {
    fakeAuditArr(['subtitles' => 'Japanese']);

    resolve(ImportedSubtitleAuditor::class)->audit($this->connection, importPayload(), null);

    $actionRequest = ActionRequest::query()->where('type', 'replace_media_file')->sole();

    expect($actionRequest->payload['selection_mode'])->toBe('automatic')
        ->and($actionRequest->payload['auto_check_key'])->toBe(sprintf('sonarr:%d:42-101', $this->connection->id))
        ->and($actionRequest->payload['candidate_fingerprint'])->not->toBeEmpty()
        ->and($actionRequest->requires_approval)->toBeFalse();

    Notification::assertSentTo(
        $this->admin,
        MediaReplacementStatusChanged::class,
        fn (MediaReplacementStatusChanged $notification): bool => str_contains($notification->message, 'requested a replacement (automatic selection)'),
    );
});

test('an import that already has the required language dispatches nothing and runs no search', function (): void {
    fakeAuditArr(['subtitles' => 'English']);

    resolve(ImportedSubtitleAuditor::class)->audit($this->connection, importPayload(), null);

    expect(ActionRequest::query()->where('type', 'replace_media_file')->count())->toBe(0);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/release'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/episodefile/501'));
});

test('an untagged series is skipped before any inspection', function (): void {
    fakeAuditArr(['tagIds' => [2]]);

    resolve(ImportedSubtitleAuditor::class)->audit($this->connection, importPayload(), null);

    expect(ActionRequest::query()->where('type', 'replace_media_file')->count())->toBe(0);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/episodefile'));
});

test('a connection with no configured tags is skipped without touching the arr', function (): void {
    fakeAuditArr();

    $this->connection->update(['settings' => ['sonarr_root_folders' => [['root_folder_id' => 1, 'path' => '/tv', 'scope' => 'tv']]]]);

    resolve(ImportedSubtitleAuditor::class)->audit($this->connection, importPayload(), null);

    expect(ActionRequest::query()->where('type', 'replace_media_file')->count())->toBe(0);

    Http::assertNothingSent();
});

test('a skipped import is logged so the no-op is distinguishable from a broken feature', function (): void {
    fakeAuditArr(['tagIds' => [2]]);

    Log::shouldReceive('debug')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Automatic subtitle check skipped.'
            && $context['reason'] === 'no_configured_tag');
    Log::shouldReceive('info')->never();
    Log::shouldReceive('warning')->never();
    Log::shouldReceive('error')->never();

    resolve(ImportedSubtitleAuditor::class)->audit($this->connection, importPayload(), null);
});

test('the check is skipped entirely when disabled', function (): void {
    // The arr surface is faked even though nothing should reach it: without a
    // fake, a stray request throws and audit()'s guard swallows it, so
    // assertNothingSent would pass with the enabled guard deleted.
    fakeAuditArr();

    resolve(MediaReplacementSettings::class)->setConfiguration([
        'subtitle_check' => ['enabled' => false, 'max_attempts_per_target' => 1, 'cooldown_hours' => 24],
    ]);

    resolve(ImportedSubtitleAuditor::class)->audit($this->connection, importPayload(), null);

    expect(ActionRequest::query()->where('type', 'replace_media_file')->count())->toBe(0);
    Http::assertNothingSent();
});

test("a replacement's own padded download id is normalized and skipped", function (): void {
    fakeAuditArr(['subtitles' => 'Japanese']);

    MediaReplacementAttempt::factory()->create([
        'service_connection_id' => $this->connection->id,
        'status' => MediaReplacementStatus::Downloading,
        'scope' => 'tv',
        'target' => ['service' => 'sonarr', 'series_id' => 42, 'episode_ids' => [101]],
        'candidate' => ['title' => 'whatever'],
        'download_id' => 'DL-ORGANIC',
    ]);

    resolve(ImportedSubtitleAuditor::class)->audit(
        $this->connection,
        importPayload(['downloadId' => ' DL-ORGANIC ']),
        null,
    );

    // Counted by the auditor's own key, not by type: the attempt factory creates
    // its own replace_media_file request to satisfy the foreign key.
    expect(ActionRequest::query()->where('payload->auto_check_key', sprintf('sonarr:%d:42-101', $this->connection->id))->count())->toBe(0);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/episodefile'));
});

test('the per-target cap stops a second attempt inside the cooldown', function (): void {
    fakeAuditArr(['subtitles' => 'Japanese']);

    ActionRequest::factory()->create([
        'type' => 'replace_media_file',
        'payload' => ['auto_check_key' => sprintf('sonarr:%d:42-101', $this->connection->id)],
        'created_at' => now()->subHour(),
    ]);

    resolve(ImportedSubtitleAuditor::class)->audit($this->connection, importPayload(), null);

    expect(ActionRequest::query()->where('type', 'replace_media_file')->count())->toBe(1);

    Notification::assertSentTo(
        $this->admin,
        MediaReplacementStatusChanged::class,
        fn (MediaReplacementStatusChanged $notification): bool => str_contains($notification->message, 'already requested 1 replacement(s)'),
    );
});

test('an attempt older than the cooldown does not block a new one', function (): void {
    fakeAuditArr(['subtitles' => 'Japanese']);

    ActionRequest::factory()->create([
        'type' => 'replace_media_file',
        'payload' => ['auto_check_key' => sprintf('sonarr:%d:42-101', $this->connection->id)],
        'created_at' => now()->subHours(48),
    ]);

    resolve(ImportedSubtitleAuditor::class)->audit($this->connection, importPayload(), null);

    expect(ActionRequest::query()->where('type', 'replace_media_file')->count())->toBe(2);
});

test('the cap reads back the auto_check_key the builder wrote', function (): void {
    fakeAuditArr(['subtitles' => 'Japanese']);

    $importedSubtitleAuditor = resolve(ImportedSubtitleAuditor::class);

    $importedSubtitleAuditor->audit($this->connection, importPayload(), null);
    $importedSubtitleAuditor->audit($this->connection, importPayload(['downloadId' => 'DL-ORGANIC-2']), null);

    expect(ActionRequest::query()->where('type', 'replace_media_file')->count())->toBe(1);

    // Without this, `count === 1` would also hold if the second call had simply
    // thrown and been swallowed by audit()'s guard — and this is the test that
    // carries the builder/consumer agreement, so it must say why it stopped.
    Notification::assertSentTo(
        $this->admin,
        MediaReplacementStatusChanged::class,
        fn (MediaReplacementStatusChanged $notification): bool => str_contains($notification->message, 'already requested 1 replacement(s)'),
    );
});

test('a low-confidence shortlist dispatches the top candidate with approval forced', function (): void {
    fakeAuditArr([
        'subtitles' => 'Japanese',
        'releases' => [[
            'guid' => 'g2', 'indexerId' => 10, 'title' => 'Tagged.Show.S01E01.WEB',
            'episodeIds' => [101], 'downloadAllowed' => true, 'rejections' => [], 'fullSeason' => false,
            'customFormatScore' => 0, 'qualityWeight' => 100, 'seeders' => 5, 'ageMinutes' => 60,
            'downloadUrl' => 'http://sonarr.local/download/g2',
        ]],
    ]);

    resolve(ImportedSubtitleAuditor::class)->audit($this->connection, importPayload(), null);

    $actionRequest = ActionRequest::query()->where('type', 'replace_media_file')->sole();

    expect($actionRequest->payload['selection_mode'])->toBe('manual')
        ->and($actionRequest->requires_approval)->toBeTrue();
});

test('a shortlist with nothing eligible notifies instead of dispatching', function (): void {
    fakeAuditArr(['subtitles' => 'Japanese', 'releases' => []]);

    resolve(ImportedSubtitleAuditor::class)->audit($this->connection, importPayload(), null);

    expect(ActionRequest::query()->where('type', 'replace_media_file')->count())->toBe(0);

    Notification::assertSentTo(
        $this->admin,
        MediaReplacementStatusChanged::class,
        fn (MediaReplacementStatusChanged $notification): bool => str_contains($notification->message, 'no eligible replacement was found'),
    );
});

test('an ambiguous snapshot notifies instead of searching', function (): void {
    fakeAuditArr(['subtitles' => 'Japanese']);

    resolve(ImportedSubtitleAuditor::class)->audit(
        $this->connection,
        importPayload(['episodes' => [['id' => 101, 'seasonNumber' => 9, 'episodeNumber' => 9]]]),
        null,
    );

    expect(ActionRequest::query()->where('type', 'replace_media_file')->count())->toBe(0);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/release'));

    Notification::assertSentTo(
        $this->admin,
        MediaReplacementStatusChanged::class,
        fn (MediaReplacementStatusChanged $notification): bool => str_contains($notification->message, 'could not identify a single file'),
    );
});

test('an upstream tag label is folded and trimmed before it is compared', function (): void {
    fakeAuditArr([
        'subtitles' => 'Japanese',
        'tags' => [['id' => 1, 'label' => '  Sub-Check  '], ['id' => 2, 'label' => 'other']],
    ]);

    resolve(ImportedSubtitleAuditor::class)->audit($this->connection, importPayload(), null);

    expect(ActionRequest::query()->where('type', 'replace_media_file')->count())->toBe(1);
});

test('a tagged Radarr import keys the cap by movie id', function (): void {
    $radarr = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878', 'api_key' => 'test', 'is_active' => true,
        'settings' => ['subtitle_check_tags' => ['sub-check']],
    ]);

    $mediaReplacementSettings = resolve(MediaReplacementSettings::class);
    $configuration = $mediaReplacementSettings->configuration();
    $configuration['guidance']['movie']['rules'] = [[
        'name' => 'CR', 'enabled' => true, 'strength' => 'guarantee', 'languages' => ['English'],
        'conditions' => [['field' => 'title', 'value' => 'CR']],
    ]];
    $mediaReplacementSettings->setConfiguration($configuration);

    Http::fake([
        'radarr.local:7878/api/v3/tag' => Http::response([['id' => 1, 'label' => 'sub-check']]),
        'radarr.local:7878/api/v3/moviefile/77' => Http::response([
            'id' => 77, 'quality' => ['quality' => ['name' => 'WEBDL-1080p']], 'size' => 100,
            'sceneName' => 'Movie.2024.OLD', 'releaseGroup' => 'OLD',
            'mediaInfo' => ['subtitles' => 'Japanese'],
        ]),
        'radarr.local:7878/api/v3/movie/42' => Http::response([
            'id' => 42, 'title' => 'Tagged Movie', 'monitored' => true, 'tags' => [1], 'movieFileId' => 77,
        ]),
        'radarr.local:7878/api/v3/history*' => Http::response(['records' => [[
            'id' => 9001, 'eventType' => 'downloadFolderImported', 'movieId' => 42, 'movieFileId' => 77,
        ]]]),
        'radarr.local:7878/api/v3/release*' => Http::response([[
            'guid' => 'g3', 'indexerId' => 10, 'title' => 'Movie.2024.CR', 'movieId' => 42,
            'downloadAllowed' => true, 'rejections' => [], 'customFormatScore' => 0,
            'qualityWeight' => 100, 'seeders' => 5, 'ageMinutes' => 60,
            'downloadUrl' => 'http://radarr.local/download/g3',
        ]]),
    ]);

    resolve(ImportedSubtitleAuditor::class)->audit(
        $radarr,
        ['eventType' => 'Download', 'movie' => ['id' => 42, 'title' => 'Tagged Movie'], 'downloadId' => 'DL-MOVIE'],
        null,
    );

    $actionRequest = ActionRequest::query()->where('type', 'replace_media_file')->sole();

    expect($actionRequest->payload['auto_check_key'])->toBe(sprintf('radarr:%d:42', $radarr->id))
        ->and($actionRequest->payload['selection_mode'])->toBe('automatic');
});

test('a specials import is inspected rather than reported as unidentifiable', function (): void {
    // Sonarr numbers Specials as season 0, which must not be coerced to "no
    // season" — MediaFileInspector would then match no episode and the operator
    // would get an "unidentifiable import" notification for every special.
    fakeAuditArr([
        'subtitles' => 'Japanese',
        'episodes' => [[
            'id' => 101, 'seriesId' => 42, 'seasonNumber' => 0, 'episodeNumber' => 1,
            'episodeFileId' => 501, 'monitored' => true, 'hasFile' => true, 'title' => 'OVA',
        ]],
    ]);

    resolve(ImportedSubtitleAuditor::class)->audit(
        $this->connection,
        importPayload(['episodes' => [['id' => 101, 'seasonNumber' => 0, 'episodeNumber' => 1]]]),
        null,
    );

    expect(ActionRequest::query()->where('type', 'replace_media_file')->count())->toBe(1);
});

test('the inspection sees the library after the import, not a warm pre-import cache', function (): void {
    fakeAuditArr(['subtitles' => 'Japanese']);

    // The webhook handler busts the arr cache only after the per-event handlers
    // run, so on the real path the auditor inherits a pre-import cache. Seed the
    // episode list as it looked before the file landed. Seeded through the cache
    // rather than through a warming HTTP call because Http::fake() merges stubs
    // and an earlier `episode*` stub would keep winning after the bust, which
    // would make this test pass whether or not the bust happened.
    new SonarrCache($this->connection)->rememberList('episodes:42', fn (): array => [[
        'id' => 101, 'seriesId' => 42, 'seasonNumber' => 1, 'episodeNumber' => 1,
        'episodeFileId' => 0, 'monitored' => true, 'hasFile' => false, 'title' => 'Ep 1',
    ]]);

    resolve(ImportedSubtitleAuditor::class)->audit($this->connection, importPayload(), null);

    expect(ActionRequest::query()->where('payload->auto_check_key', sprintf('sonarr:%d:42-101', $this->connection->id))->count())->toBe(1);
});

test('a payload that does not name a single episode is skipped rather than widened', function (): void {
    // Without the guard the null episode number reaches inspect() as "no episode
    // filter", so the whole season matches and this one-episode fixture would be
    // replaced on the strength of a payload that never identified it.
    fakeAuditArr();

    resolve(ImportedSubtitleAuditor::class)->audit(
        $this->connection,
        importPayload(['episodes' => [['id' => 101, 'seasonNumber' => 1]]]),
        null,
    );

    expect(ActionRequest::query()->where('type', 'replace_media_file')->count())->toBe(0);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v3/episodefile'));
    Notification::assertNothingSent();
});

test('a failure inside the audit is logged rather than escaping to the webhook handler', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/tag' => Http::response([['id' => 1, 'label' => 'sub-check']]),
        'sonarr.local:8989/api/v3/series/42' => Http::response(status: 500),
    ]);

    Log::shouldReceive('debug')->zeroOrMoreTimes();
    Log::shouldReceive('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Automatic subtitle check failed.'
            && $context['exception'] === RequestException::class);

    resolve(ImportedSubtitleAuditor::class)->audit($this->connection, importPayload(), null);

    expect(ActionRequest::query()->where('type', 'replace_media_file')->count())->toBe(0);
});
