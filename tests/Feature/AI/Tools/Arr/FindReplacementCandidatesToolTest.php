<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Arr\FindReplacementCandidatesTool;
use App\Models\ServiceConnection;
use App\Settings\MediaReplacementSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

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

function fakeInspectableEpisode(): void
{
    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response(['id' => 42, 'title' => 'Trusted Anime', 'seriesType' => 'anime']),
        'sonarr.local:8989/api/v3/episode?seriesId=42' => Http::response([
            ['id' => 101, 'seasonNumber' => 1, 'episodeNumber' => 1, 'episodeFileId' => 501],
        ]),
        'sonarr.local:8989/api/v3/episodefile/501' => Http::response([
            'id' => 501, 'sceneName' => 'Trusted.Anime.S01E01.OLD', 'mediaInfo' => ['subtitles' => 'Japanese'],
        ]),
        'sonarr.local:8989/api/v3/history*' => Http::response(['records' => []]),
        'sonarr.local:8989/api/v3/release*' => Http::response([[
            'guid' => 'g1', 'indexerId' => 10, 'title' => 'Trusted.Anime.S01E01.CR',
            'episodeIds' => [101], 'downloadAllowed' => true, 'rejections' => [], 'fullSeason' => false,
            'customFormatScore' => 0, 'qualityWeight' => 100, 'seeders' => 5, 'ageMinutes' => 60,
        ]]),
    ]);
}

test('the find tool is a read tool', function (): void {
    expect((new FindReplacementCandidatesTool)->risk())->toBe(Risk::Read);
});

test('inspects then returns a ranked shortlist', function (): void {
    fakeInspectableEpisode();

    $result = json_decode((new FindReplacementCandidatesTool)->handle(new Request([
        'service' => 'sonarr', 'item_id' => 42, 'season_number' => 1, 'episode_number' => 1,
        'absolute_episode_number' => null, 'required_languages' => null, 'limit' => null,
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result['candidates'])->toHaveCount(1)
        ->and($result['candidates'][0]['confidence'])->toBe(98)
        ->and($result['automatic_candidate'])->toBeNull();
});

test('refuses to search an ambiguous target', function (): void {
    Http::fake([
        'sonarr.local:8989/api/v3/series/42' => Http::response(['id' => 42, 'title' => 'A', 'seriesType' => 'anime']),
        'sonarr.local:8989/api/v3/episode?seriesId=42' => Http::response([
            ['id' => 101, 'seasonNumber' => 1, 'episodeNumber' => 1, 'episodeFileId' => 501],
            ['id' => 102, 'seasonNumber' => 1, 'episodeNumber' => 2, 'episodeFileId' => 502],
        ]),
    ]);

    $result = json_decode((new FindReplacementCandidatesTool)->handle(new Request([
        'service' => 'sonarr', 'item_id' => 42, 'season_number' => 1, 'episode_number' => null,
        'absolute_episode_number' => null, 'required_languages' => null, 'limit' => null,
    ])), true, flags: JSON_THROW_ON_ERROR);

    expect($result['ambiguous'])->toBeTrue()
        ->and($result)->not->toHaveKey('candidates');

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/v3/release'));
});
