<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\MediaReplacement\SeriesSubtitleAuditor;
use App\Services\MediaReplacement\SeriesSubtitleAuditUnavailable;
use App\Settings\MediaReplacementSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();

    $this->connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
        'settings' => [
            'sonarr_root_folders' => [['root_folder_id' => 1, 'path' => '/anime', 'scope' => 'anime']],
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
            'tv' => ['notes' => '', 'rules' => []],
            'movie' => ['notes' => '', 'rules' => []],
        ],
    ]);
});

/**
 * @param  list<array<string, mixed>>  $episodes
 * @param  list<array<string, mixed>>  $files
 */
function fakeSeriesAudit(array $episodes, array $files, string $path = '/anime/Show'): void
{
    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response([
            'id' => 42, 'title' => 'Show', 'path' => $path, 'seriesType' => 'anime',
        ]),
        'sonarr.local:8989/api/v3/episodefile*' => Http::response($files),
        'sonarr.local:8989/api/v3/episode*' => Http::response($episodes),
    ]);
}

/**
 * @return array<string, mixed>
 */
function auditEpisode(int $id, int $number, ?int $fileId, int $season = 1, bool $monitored = true): array
{
    return [
        'id' => $id, 'seriesId' => 42, 'seasonNumber' => $season, 'episodeNumber' => $number,
        'episodeFileId' => $fileId ?? 0, 'monitored' => $monitored, 'hasFile' => $fileId !== null,
    ];
}

/**
 * @return array<string, mixed>
 */
function auditFile(int $id, string|array $subtitles): array
{
    return ['id' => $id, 'seriesId' => 42, 'mediaInfo' => ['subtitles' => $subtitles]];
}

test('an episode without the required language is missing and one with it is ok', function (): void {
    fakeSeriesAudit(
        [auditEpisode(101, 1, 501), auditEpisode(102, 2, 502)],
        [auditFile(501, 'Japanese'), auditFile(502, 'English/Japanese')],
    );

    $audit = resolve(SeriesSubtitleAuditor::class)->audit($this->connection, 42)->toArray();

    expect($audit['counts'])->toBe(['total' => 2, 'ok' => 1, 'missing' => 1, 'skipped' => 0])
        ->and($audit['missing'])->toBe([['label' => 'S01E01', 'missing_languages' => ['eng']]])
        ->and($audit['scope'])->toBe('anime')
        ->and($audit['series_title'])->toBe('Show');
});

test('a file with no subtitles at all is missing', function (): void {
    fakeSeriesAudit([auditEpisode(101, 1, 501)], [auditFile(501, '')]);

    $audit = resolve(SeriesSubtitleAuditor::class)->audit($this->connection, 42)->toArray();

    expect($audit['missing'])->toBe([['label' => 'S01E01', 'missing_languages' => ['eng']]]);
});

test('subtitle strings and arrays are read alike', function (): void {
    fakeSeriesAudit(
        [auditEpisode(101, 1, 501), auditEpisode(102, 2, 502)],
        [auditFile(501, 'eng/jpn'), auditFile(502, ['English', 'Japanese'])],
    );

    $audit = resolve(SeriesSubtitleAuditor::class)->audit($this->connection, 42)->toArray();

    expect($audit['counts'])->toBe(['total' => 2, 'ok' => 2, 'missing' => 0, 'skipped' => 0]);
});

test('episodes are skipped with their reason', function (): void {
    fakeSeriesAudit(
        [
            auditEpisode(101, 1, null),
            auditEpisode(102, 2, 502, monitored: false),
            auditEpisode(103, 1, 503, season: 0),
            auditEpisode(104, 4, 504),
            auditEpisode(105, 5, 504),
        ],
        [auditFile(502, ''), auditFile(503, ''), auditFile(504, '')],
    );

    $audit = resolve(SeriesSubtitleAuditor::class)->audit($this->connection, 42)->toArray();

    expect($audit['missing'])->toBe([])
        ->and($audit['skipped'])->toBe([
            ['label' => 'S01E01', 'reason' => 'no_file'],
            ['label' => 'S01E02', 'reason' => 'unmonitored'],
            ['label' => 'S00E01', 'reason' => 'special'],
            ['label' => 'S01E04', 'reason' => 'shared_file'],
            ['label' => 'S01E05', 'reason' => 'shared_file'],
        ]);
});

test('specials are audited when season 0 is asked for', function (): void {
    fakeSeriesAudit(
        [auditEpisode(101, 1, 501), auditEpisode(103, 1, 503, season: 0)],
        [auditFile(501, ''), auditFile(503, '')],
    );

    $audit = resolve(SeriesSubtitleAuditor::class)->audit($this->connection, 42, 0)->toArray();

    expect($audit['season_number'])->toBe(0)
        ->and($audit['counts']['total'])->toBe(1)
        ->and($audit['missing'])->toBe([['label' => 'S00E01', 'missing_languages' => ['eng']]]);
});

test('a missing episode with a pending replacement is skipped as in flight', function (): void {
    fakeSeriesAudit([auditEpisode(101, 1, 501)], [auditFile(501, '')]);

    ActionRequest::factory()->create([
        'type' => 'replace_media_file',
        'status' => ActionRequestStatus::Pending,
        'payload' => ['target' => [
            'service_connection_id' => $this->connection->id,
            'series_id' => 42,
            'season_number' => 1,
            'episode_numbers' => [1],
        ]],
    ]);

    $audit = resolve(SeriesSubtitleAuditor::class)->audit($this->connection, 42)->toArray();

    expect($audit['skipped'])->toBe([['label' => 'S01E01', 'reason' => 'replacement_in_flight']])
        ->and($audit['missing'])->toBe([]);
});

test('a missing episode at its attempt cap is skipped as cap reached', function (): void {
    fakeSeriesAudit([auditEpisode(101, 1, 501)], [auditFile(501, '')]);

    ActionRequest::factory()->create([
        'type' => 'replace_media_file',
        'status' => ActionRequestStatus::Completed,
        'payload' => ['auto_check_key' => sprintf('sonarr:%d:42-101', $this->connection->id)],
        'created_at' => now(),
    ]);

    $audit = resolve(SeriesSubtitleAuditor::class)->audit($this->connection, 42)->toArray();

    expect($audit['skipped'])->toBe([['label' => 'S01E01', 'reason' => 'cap_reached']]);
});

test('the audit reads the series in three Sonarr calls', function (): void {
    fakeSeriesAudit(
        [auditEpisode(101, 1, 501), auditEpisode(102, 2, 502), auditEpisode(103, 3, 503)],
        [auditFile(501, ''), auditFile(502, ''), auditFile(503, '')],
    );

    resolve(SeriesSubtitleAuditor::class)->audit($this->connection, 42);

    Http::assertSentCount(3);
});

test('an unconfigured root folder makes the audit unavailable', function (): void {
    fakeSeriesAudit([auditEpisode(101, 1, 501)], [auditFile(501, '')], '/elsewhere/Show');

    try {
        resolve(SeriesSubtitleAuditor::class)->audit($this->connection, 42);
        $this->fail('Expected SeriesSubtitleAuditUnavailable.');
    } catch (SeriesSubtitleAuditUnavailable $seriesSubtitleAuditUnavailable) {
        expect($seriesSubtitleAuditUnavailable->reason)->toBe('unconfigured_root_scope');
    }
});
