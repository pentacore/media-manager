<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use App\Settings\MediaReplacementSettings;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Support\Facades\Http;

/**
 * Fixture shapes lifted from tests/Feature/Media/MediaReplacementControllerTest.php
 * so this browser flow exercises the same Radarr HTTP surface the JSON
 * endpoints are already covered against, plus the extra movie-detail fields
 * (hasFile, movieFile) the Radarr\Movies\Show page itself needs to render the
 * "Replace file" trigger and file-info panel.
 */
test('member replaces a movie file end to end from the show page', function (): void {
    $this->seed(ActionTypeConfigSeeder::class);
    resolve(MediaReplacementSettings::class)->setConfiguration([
        'automatic_selection_enabled' => false,
        'automatic_selection_threshold' => 90,
        'global_languages' => ['English'],
        'scoped_languages' => ['anime' => null, 'tv' => null, 'movie' => null],
        'season_pack_policy' => 'approval_required',
        'guidance' => [
            'anime' => ['notes' => '', 'rules' => []],
            'tv' => ['notes' => '', 'rules' => []],
            'movie' => [
                'notes' => '',
                'rules' => [[
                    'name' => 'Trusted',
                    'enabled' => true,
                    'strength' => 'guarantee',
                    'languages' => ['English'],
                    'conditions' => [['field' => 'title', 'value' => 'CR']],
                ]],
            ],
        ],
    ]);

    $connection = ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878']);

    Http::fake([
        'radarr.local:7878/api/v3/movie/10' => Http::response([
            'id' => 10,
            'title' => 'A Movie',
            'titleSlug' => 'a-movie-2026',
            'year' => 2026,
            'status' => 'released',
            'monitored' => true,
            'hasFile' => true,
            'qualityProfileId' => 1,
            'sizeOnDisk' => 5_000_000_000,
            'images' => [],
            'overview' => 'A movie about a thing.',
            'runtime' => 120,
            'studio' => 'Example Studio',
            'rootFolderPath' => '/movies',
            'movieFileId' => 5,
            'movieFile' => [
                'quality' => ['quality' => ['name' => 'Bluray-1080p']],
                'size' => 5_000_000_000,
                'relativePath' => 'A Movie (2026)/A.Movie.2026.1080p.BluRay.mkv',
            ],
        ]),
        'radarr.local:7878/api/v3/moviefile/5' => Http::response([
            'id' => 5,
            'movieId' => 10,
            'sceneName' => 'A.Movie.2026.1080p.BluRay',
            'releaseGroup' => 'GROUP',
            'quality' => ['quality' => ['name' => 'Bluray-1080p']],
            'mediaInfo' => ['subtitles' => 'English / Swedish'],
        ]),
        'radarr.local:7878/api/v3/history*' => Http::response([
            'records' => [
                ['id' => 777, 'eventType' => 'grabbed', 'movieId' => 10, 'downloadId' => 'XYZ'],
            ],
        ]),
        'radarr.local:7878/api/v3/release*' => Http::response([[
            'guid' => 'guid-1',
            'indexerId' => 10,
            'title' => 'A.Movie.2026.CR.1080p.BluRay',
            'releaseGroup' => 'GROUP',
            'movieId' => 10,
            'episodeIds' => [],
            'downloadAllowed' => true,
            'rejections' => [],
            'fullSeason' => false,
            'customFormats' => [],
            'customFormatScore' => 10,
            'qualityWeight' => 100,
            'seeders' => 5,
            'ageMinutes' => 60,
            'downloadUrl' => 'https://secret.example/download',
            'magnetUrl' => 'magnet:?xt=secret',
        ]]),
    ]);

    $this->actingAs(User::factory()->member()->create());

    visit(route('media.movies.show', ['id' => 10], absolute: false))
        ->assertSee('A Movie')
        ->assertNoSmoke()
        ->click('[data-replacement-trigger]')
        ->assertSeeIn('[data-replacement-current-file]', 'A Movie')
        ->assertSeeIn('[data-replacement-current-file]', 'Bluray-1080p')
        ->click('[data-replacement-search]')
        ->assertSeeIn('[data-replacement-candidate-row]', 'A.Movie.2026.CR.1080p.BluRay')
        ->click('[data-replacement-candidate-row]')
        ->click('[data-replacement-submit]')
        ->assertSee('Replacement queued')
        ->assertNoSmoke();
});
