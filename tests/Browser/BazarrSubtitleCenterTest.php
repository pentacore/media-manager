<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use App\Enums\BazarrServiceRole;
use App\Models\ActionRequest;
use App\Models\BazarrServiceLink;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('viewer can browse the subtitle center', function (): void {
    $bazarr = ServiceConnection::factory()->bazarr()->create([
        'name' => 'Primary Bazarr',
        'url' => 'http://bazarr.test',
        'api_key' => 'bazarr-secret',
    ]);

    Http::fake([
        'bazarr.test/api/system/health' => Http::response(['data' => []]),
        'bazarr.test/api/episodes/wanted*' => Http::response(['data' => [], 'total' => 0]),
        'bazarr.test/api/movies/wanted*' => Http::response(['data' => [], 'total' => 0]),
    ]);

    $this->actingAs(User::factory()->create());

    visit(route('bazarr.overview', ['connection' => $bazarr->id], false))
        ->assertSee('Subtitle Center')
        ->assertSee('Missing subtitles')
        ->assertNoSmoke()
        ->click('@subtitle-tab-missing')
        ->assertPathIs('/subtitles/missing')
        ->assertSee('Nothing is currently missing')
        ->assertNoSmoke()
        ->click('@subtitle-tab-library')
        ->assertPathIs('/subtitles/library')
        ->assertSee('No subtitle inventory found')
        ->assertNoSmoke()
        ->click('@subtitle-tab-history')
        ->assertPathIs('/subtitles/history')
        ->assertSee('No subtitle history found')
        ->assertNoSmoke();
});

test('member searches and requests an exact subtitle from the item drawer', function (): void {
    $this->seed(ActionTypeConfigSeeder::class);
    $bazarr = ServiceConnection::factory()->bazarr()->create([
        'name' => 'Primary Bazarr',
        'url' => 'http://bazarr.test',
        'api_key' => 'bazarr-secret',
    ]);
    $radarr = ServiceConnection::factory()->radarr()->create();
    BazarrServiceLink::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $radarr->id,
        'role' => BazarrServiceRole::Radarr,
    ]);
    $movie = [
        'radarrId' => 801,
        'title' => 'Example Movie',
        'sceneName' => 'Example.Movie.2024.1080p',
        'path' => '/media/movies/Example Movie (2024)',
        'monitored' => true,
        'missing_subtitles' => [[
            'name' => 'Swedish',
            'code2' => 'sv',
            'code3' => 'swe',
            'forced' => false,
            'hi' => false,
        ]],
        'subtitles' => [],
    ];
    $candidate = [
        'provider' => 'AnimeTosho',
        'subtitle' => 'private-provider-id',
        'language' => 'swe',
        'forced' => false,
        'hearing_impaired' => false,
        'score' => 98,
        'release_info' => ['Example.Movie.2024.1080p'],
    ];

    Http::fake(function (Request $request) use ($movie, $candidate) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match ($path) {
            '/api/movies/wanted' => Http::response(['data' => [$movie], 'total' => 1]),
            '/api/movies' => Http::response(['data' => [$movie], 'total' => 1]),
            '/api/movies/history' => Http::response(['data' => [], 'total' => 0]),
            '/api/providers/movies' => Http::response(['data' => [$candidate]]),
            '/api/swagger.json' => Http::response([
                'swagger' => '2.0',
                'basePath' => '/api',
                'info' => ['title' => 'Bazarr', 'version' => '1.6.0'],
                'paths' => [
                    '/providers/episodes' => [
                        'get' => ['responses' => ['200' => ['description' => 'OK']]],
                        'post' => ['responses' => ['204' => ['description' => 'OK']]],
                    ],
                    '/providers/movies' => [
                        'get' => ['responses' => ['200' => ['description' => 'OK']]],
                        'post' => ['responses' => ['204' => ['description' => 'OK']]],
                    ],
                    '/episodes/subtitles' => [
                        'patch' => ['responses' => ['204' => ['description' => 'OK']]],
                    ],
                    '/movies/subtitles' => [
                        'patch' => ['responses' => ['204' => ['description' => 'OK']]],
                    ],
                ],
            ]),
            default => Http::response(['data' => []]),
        };
    });

    $this->actingAs(User::factory()->member()->create());

    visit(route('bazarr.missing', ['connection' => $bazarr->id], false))
        ->assertSee('Example Movie')
        ->click('@subtitle-item-movie-801')
        ->assertSee('Find subtitles')
        ->click('@subtitle-search')
        ->assertSee('AnimeTosho')
        ->click('@candidate-request-0')
        ->assertSee('Subtitle operation added to the Action Queue.')
        ->assertNoSmoke();

    expect(ActionRequest::query()->where('type', 'bazarr_download_exact')->count())->toBe(1);
});
