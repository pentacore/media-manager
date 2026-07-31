<?php

declare(strict_types=1);

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
    resolve(MediaReplacementSettings::class)->setConfiguration([
        'subtitle_check' => ['enabled' => false, 'max_attempts_per_target' => 1, 'cooldown_hours' => 24],
    ]);

    resolve(ImportedSubtitleAuditor::class)->audit($this->connection, importPayload(), null);

    expect(ActionRequest::query()->where('type', 'replace_media_file')->count())->toBe(0);
    Http::assertNothingSent();
});

test("a replacement's own import is skipped", function (): void {
    fakeAuditArr(['subtitles' => 'Japanese']);

    MediaReplacementAttempt::factory()->create([
        'service_connection_id' => $this->connection->id,
        'status' => MediaReplacementStatus::Downloading,
        'scope' => 'tv',
        'target' => ['service' => 'sonarr', 'series_id' => 42, 'episode_ids' => [101]],
        'candidate' => ['title' => 'whatever'],
        'download_id' => 'DL-ORGANIC',
    ]);

    resolve(ImportedSubtitleAuditor::class)->audit($this->connection, importPayload(), null);

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

    $auditor = resolve(ImportedSubtitleAuditor::class);

    $auditor->audit($this->connection, importPayload(), null);
    $auditor->audit($this->connection, importPayload(['downloadId' => 'DL-ORGANIC-2']), null);

    expect(ActionRequest::query()->where('type', 'replace_media_file')->count())->toBe(1);
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

