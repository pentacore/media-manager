<?php

declare(strict_types=1);

use App\Enums\SubtitleCaseAttemptOutcome;
use App\Enums\SubtitleCaseAttemptType;
use App\Enums\SubtitleCaseStatus;
use App\Models\ServiceConnection;
use App\Models\SubtitleCase;
use App\Models\SubtitleCaseAttempt;
use App\Services\Bazarr\SubtitleAdvisorProjection;
use App\Services\Bazarr\SubtitleCaseFingerprint;
use App\Settings\MediaReplacementSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Date::setTestNow('2026-07-17 12:00:00');
    Http::preventStrayRequests();

    $this->bazarr = ServiceConnection::factory()->bazarr()->create([
        'url' => 'http://bazarr.test',
        'api_key' => 'bazarr-secret',
    ]);
    $this->mappedSonarr = ServiceConnection::factory()->sonarr()->create([
        'name' => 'Mapped Sonarr',
        'url' => 'http://sonarr-mapped.test',
        'api_key' => 'mapped-secret',
    ]);
    ServiceConnection::factory()->sonarr()->create([
        'name' => 'Unrelated Sonarr',
        'url' => 'http://sonarr-unrelated.test',
        'api_key' => 'unrelated-secret',
    ]);
    resolve(MediaReplacementSettings::class)->setConfiguration([
        'automatic_selection_enabled' => true,
        'automatic_selection_threshold' => 90,
        'global_languages' => ['English'],
        'scoped_languages' => ['anime' => null, 'tv' => null, 'movie' => null],
        'season_pack_policy' => 'approval_required',
        'guidance' => [
            'anime' => [
                'notes' => 'Use subtitle evidence only.',
                'rules' => [[
                    'name' => 'Trusted English',
                    'enabled' => true,
                    'strength' => 'guarantee',
                    'languages' => ['English'],
                    'conditions' => [['field' => 'title', 'value' => 'CR']],
                ]],
            ],
            'tv' => ['notes' => '', 'rules' => []],
            'movie' => ['notes' => '', 'rules' => []],
        ],
    ]);

    $this->fileIdentity = [
        'service' => 'sonarr',
        'service_connection_id' => $this->mappedSonarr->id,
        'file_ids' => [501],
        'media_ids' => [701],
        'size' => 1_500_000_000,
        'date_added' => '2026-06-01T00:00:00Z',
        'scene_name' => 'Frieren.S01E01.1080p.WEB',
    ];
    $this->case = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $this->bazarr->id,
        'service_connection_id' => $this->mappedSonarr->id,
        'status' => SubtitleCaseStatus::ReplacementEligible,
        'target_ids' => [
            'series_id' => 101,
            'episode_id' => 701,
            'episode_file_id' => 501,
        ],
        'file_fingerprint' => resolve(SubtitleCaseFingerprint::class)->file($this->fileIdentity),
        'required_languages' => [[
            'code' => 'eng',
            'forced' => false,
            'hearing_impaired' => false,
        ]],
        'evidence' => [
            'display_name' => 'Frieren S01E01',
            'missing_languages' => ['eng'],
            'current_subtitles' => ['jpn'],
            'private_path' => '/anime/private/Frieren.mkv',
        ],
        'observed_at' => '2026-07-01T00:00:00Z',
    ]);
    SubtitleCaseAttempt::factory()->count(2)->create([
        'subtitle_case_id' => $this->case->id,
        'type' => SubtitleCaseAttemptType::Probe,
        'outcome' => SubtitleCaseAttemptOutcome::Empty,
        'started_at' => '2026-07-15T00:00:00Z',
        'completed_at' => '2026-07-15T00:01:00Z',
    ]);
});

test('it projects one bounded replacement eligible case using its mapped connection', function (): void {
    fakeSubtitleAdvisorProjectionApis(7);

    $projection = resolve(SubtitleAdvisorProjection::class)->forCase($this->case);

    expect($projection)
        ->toMatchArray([
            'case_id' => $this->case->id,
            'bazarr_connection_id' => $this->bazarr->id,
            'service' => 'sonarr',
            'service_connection_id' => $this->mappedSonarr->id,
            'scope' => 'anime',
            'display_name' => 'Frieren S01E01',
            'required_languages' => ['eng'],
            'current_subtitles' => ['jpn'],
            'bazarr_evidence' => [
                'first_seen_at' => '2026-07-01T00:00:00Z',
                'empty_probe_count' => 2,
                'last_probe_at' => '2026-07-15T00:00:00Z',
                'download_attempted' => false,
            ],
        ])
        ->and($projection['replacement']['candidate_count'])->toBe(5)
        ->and($projection['replacement']['candidates'])->toHaveCount(5)
        ->and($projection['replacement']['automatic_candidate'])->toBeArray()
        ->and($projection['replacement']['automatic_candidate']['fingerprint'])
        ->toBe($projection['replacement']['candidates'][0]['fingerprint']);

    $encoded = json_encode($projection, JSON_THROW_ON_ERROR);

    expect(strlen($encoded))->toBeLessThanOrEqual(12_000)
        ->and($encoded)
        ->not->toContain(
            '/anime/private',
            'sonarr-mapped.test',
            'sonarr-unrelated.test',
            'mapped-secret',
            'unrelated-secret',
            'https://secret.example',
            'magnet:?xt=secret',
            'rawSpecifications',
        );

    Http::assertSent(fn (Request $request): bool => str_starts_with(
        $request->url(),
        'http://sonarr-mapped.test/',
    ));
    Http::assertNotSent(fn (Request $request): bool => str_starts_with(
        $request->url(),
        'http://sonarr-unrelated.test/',
    ));
});

test('it accepts advisor running cases but rejects every other status', function (): void {
    fakeSubtitleAdvisorProjectionApis(1);
    $this->case->update(['status' => SubtitleCaseStatus::AdvisorRunning]);

    expect(resolve(SubtitleAdvisorProjection::class)->forCase($this->case))
        ->toHaveKey('case_id', $this->case->id);

    $this->case->update(['status' => SubtitleCaseStatus::NeedsReview]);

    expect(fn (): array => resolve(SubtitleAdvisorProjection::class)->forCase($this->case))
        ->toThrow(InvalidArgumentException::class, 'not eligible for Advisor inspection');
});

test('it rejects a changed installed file before searching releases', function (): void {
    $this->fileIdentity['size'] = 1_600_000_000;
    fakeSubtitleAdvisorProjectionApis(1, size: 1_600_000_000);

    expect(fn (): array => resolve(SubtitleAdvisorProjection::class)->forCase($this->case))
        ->toThrow(InvalidArgumentException::class, 'installed file changed');

    Http::assertNotSent(fn (Request $request): bool => str_contains(
        $request->url(),
        '/release',
    ));
});

test('it drops scheme-only URIs that carry no slash past the text sanitizer', function (): void {
    fakeSubtitleAdvisorProjectionApis(
        releaseCount: 3,
        releaseTitle: 'Trusted.Anime.CR.magnet:?xt=urn:btih:private-hash',
        releaseGroup: 'file:private-group',
    );

    $projection = resolve(SubtitleAdvisorProjection::class)->forCase($this->case);

    // Guard the assertions below against passing because nothing was projected.
    expect($projection['replacement']['candidate_count'])->toBeGreaterThan(0);

    expect(json_encode($projection, JSON_THROW_ON_ERROR))
        ->not->toContain('magnet:')
        ->not->toContain('file:')
        ->not->toContain('private-hash')
        ->not->toContain('private-group');
});

function fakeSubtitleAdvisorProjectionApis(
    int $releaseCount,
    int $size = 1_500_000_000,
    ?string $releaseTitle = null,
    ?string $releaseGroup = null,
): void {
    $releases = [];

    foreach (range(1, $releaseCount) as $index) {
        $releases[] = [
            'guid' => 'guid-'.$index,
            'indexerId' => 10,
            'title' => $releaseTitle ?? str_repeat('Trusted.Anime.CR.', 20).$index,
            'releaseGroup' => $releaseGroup ?? 'Trusted',
            'episodeIds' => [701],
            'downloadAllowed' => true,
            'rejections' => [],
            'fullSeason' => false,
            'customFormats' => [[
                'name' => 'Trusted',
                'specifications' => ['rawSpecifications'],
            ]],
            'customFormatScore' => 100 - $index,
            'qualityWeight' => 100,
            'seeders' => 20 - $index,
            'ageMinutes' => $index,
            'size' => 2_000_000_000,
            'downloadUrl' => 'https://secret.example/download/'.$index,
            'magnetUrl' => 'magnet:?xt=secret-'.$index,
        ];
    }

    Http::fake([
        'sonarr-mapped.test/api/v3/series/101' => Http::response([
            'id' => 101,
            'title' => 'Frieren',
            'seriesType' => 'anime',
        ]),
        'sonarr-mapped.test/api/v3/episode?seriesId=101' => Http::response([[
            'id' => 701,
            'seasonNumber' => 1,
            'episodeNumber' => 1,
            'absoluteEpisodeNumber' => 1,
            'episodeFileId' => 501,
            'monitored' => true,
        ]]),
        'sonarr-mapped.test/api/v3/episodefile/501' => Http::response([
            'id' => 501,
            'seriesId' => 101,
            'sceneName' => 'Frieren.S01E01.1080p.WEB',
            'releaseGroup' => 'Current',
            'size' => $size,
            'dateAdded' => '2026-06-01T00:00:00Z',
            'quality' => ['quality' => ['name' => 'WEBDL-1080p']],
            'mediaInfo' => ['subtitles' => 'Japanese'],
            'path' => '/anime/private/Frieren.mkv',
        ]),
        'sonarr-mapped.test/api/v3/history*' => Http::response(['records' => []]),
        'sonarr-mapped.test/api/v3/release*' => Http::response($releases),
    ]);
}
