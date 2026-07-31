<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Services\Bazarr\BazarrClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    Http::preventStrayRequests();

    $connection = ServiceConnection::factory()->bazarr()->create([
        'url' => 'http://bazarr.test',
        'api_key' => 'write-secret',
    ]);
    $this->client = new BazarrClient($connection);
});

test('manual searches return bounded sanitized candidates with opaque fingerprints', function (
    string $method,
    string $url,
    string $queryKey,
): void {
    $rawCandidates = [];

    for ($index = 1; $index <= 30; $index++) {
        $rawCandidates[] = [
            'provider' => 'Provider '.$index,
            'subtitle' => 'raw-subtitle-'.$index,
            'url' => 'https://provider.test/subtitle/'.$index,
            'language' => 'sv',
            'forced' => 'False',
            'hearing_impaired' => 'True',
            'score' => 100 - $index,
            'release_info' => ['Release '.$index],
            'original_format' => 'False',
            'uploader' => 'Uploader',
        ];
    }

    Http::fake([$url.'*' => Http::response(['data' => $rawCandidates])]);

    $results = $this->client->{$method}(42);

    expect($results)
        ->toHaveCount(25)
        ->and($results[0])
        ->toHaveKeys(['fingerprint', 'provider', 'language', 'forced', 'hearing_impaired', 'score', 'release_info'])
        ->not->toHaveKeys(['subtitle', 'url'])
        ->and($results[0]['fingerprint'])->toMatch('/^[a-f0-9]{64}$/');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_starts_with($request->url(), $url)
        && $request[$queryKey] === 42);
})->with([
    ['searchEpisode', 'http://bazarr.test/api/providers/episodes', 'episodeid'],
    ['searchMovie', 'http://bazarr.test/api/providers/movies', 'radarrid'],
]);

test('best episode download resolves the series and sends Bazarr form fields', function (): void {
    Http::fake([
        'bazarr.test/api/episodes?*' => Http::response(['data' => [[
            'sonarrSeriesId' => 7,
            'sonarrEpisodeId' => 42,
        ]]]),
        'bazarr.test/api/episodes/subtitles' => Http::response('', 204),
    ]);

    $this->client->downloadBestEpisode(42, 'sv', forced: true, hearingImpaired: false);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && $request->url() === 'http://bazarr.test/api/episodes/subtitles'
        && $request->data() === [
            'seriesid' => 7,
            'episodeid' => 42,
            'language' => 'sv',
            'forced' => 'true',
            'hi' => 'false',
        ]);
});

test('best movie download sends Bazarr form fields', function (): void {
    Http::fake(['bazarr.test/api/movies/subtitles' => Http::response('', 204)]);

    $this->client->downloadBestMovie(84, 'en', forced: false, hearingImpaired: true);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && $request->url() === 'http://bazarr.test/api/movies/subtitles'
        && $request->data() === [
            'radarrid' => 84,
            'language' => 'en',
            'forced' => 'false',
            'hi' => 'true',
        ]);
});

test('exact episode download re-searches and resolves the opaque candidate server side', function (): void {
    $candidate = [
        'provider' => 'OpenSubtitles',
        'subtitle' => 'provider-secret-id',
        'url' => 'https://provider.test/private',
        'language' => 'sv',
        'forced' => 'False',
        'hearing_impaired' => 'True',
        'score' => 97,
        'release_info' => ['Release.Name'],
        'original_format' => 'True',
    ];

    Http::fake([
        'bazarr.test/api/providers/episodes*' => Http::response(['data' => [$candidate]]),
    ]);

    $fingerprint = $this->client->searchEpisode(42)[0]['fingerprint'];
    $this->client->downloadExactEpisode([
        'series_id' => 7,
        'episode_id' => 42,
        'fingerprint' => $fingerprint,
        'subtitle' => 'browser-injected',
        'url' => 'https://attacker.test',
    ]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://bazarr.test/api/providers/episodes'
        && $request->data() === [
            'seriesid' => 7,
            'episodeid' => 42,
            'hi' => 'True',
            'forced' => 'False',
            'original_format' => 'True',
            'provider' => 'OpenSubtitles',
            'subtitle' => 'provider-secret-id',
        ]);
});

test('exact movie download re-searches and resolves the opaque candidate server side', function (): void {
    $candidate = [
        'provider' => 'AnimeTosho',
        'subtitle' => 'private-id',
        'language' => 'en',
        'forced' => 'True',
        'hearing_impaired' => 'False',
        'score' => 88,
        'release_info' => ['Movie.Release'],
        'original_format' => 'False',
    ];

    Http::fake([
        'bazarr.test/api/providers/movies*' => Http::response(['data' => [$candidate]]),
    ]);

    $fingerprint = $this->client->searchMovie(84)[0]['fingerprint'];
    $this->client->downloadExactMovie([
        'radarr_id' => 84,
        'fingerprint' => $fingerprint,
    ]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://bazarr.test/api/providers/movies'
        && $request->data()['subtitle'] === 'private-id'
        && $request->data()['provider'] === 'AnimeTosho');
});

test('episode and movie uploads use the exact multipart fields', function (): void {
    Http::fake([
        'bazarr.test/api/episodes/subtitles' => Http::response('', 204),
        'bazarr.test/api/movies/subtitles' => Http::response('', 204),
    ]);
    $episodeFile = UploadedFile::fake()->createWithContent('episode.srt', "1\n00:00:00,000 --> 00:00:01,000\nHello\n");
    $movieFile = UploadedFile::fake()->createWithContent('movie.ass', '[Script Info]');

    $this->client->uploadEpisode(7, 42, 'sv', false, true, $episodeFile);
    $this->client->uploadMovie(84, 'en', true, false, $movieFile);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://bazarr.test/api/episodes/subtitles'
        && $request->isMultipart()
        && $request->hasFile('file', filename: 'episode.srt')
        && str_contains($request->body(), 'name="seriesid"')
        && str_contains($request->body(), "\r\n7\r\n")
        && str_contains($request->body(), 'name="episodeid"')
        && str_contains($request->body(), 'name="language"'));

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://bazarr.test/api/movies/subtitles'
        && $request->hasFile('file', filename: 'movie.ass')
        && str_contains($request->body(), 'name="radarrid"')
        && str_contains($request->body(), "\r\n84\r\n"));
});

test('subtitle deletion uses server-local track selections', function (): void {
    Http::fake([
        'bazarr.test/api/episodes/subtitles' => Http::response('', 204),
        'bazarr.test/api/movies/subtitles' => Http::response('', 204),
    ]);

    $this->client->deleteEpisodeSubtitle([
        'series_id' => 7,
        'episode_id' => 42,
        'language' => 'sv',
        'forced' => false,
        'hearing_impaired' => true,
        'path' => '/media/private/episode.sv.srt',
    ]);
    $this->client->deleteMovieSubtitle([
        'radarr_id' => 84,
        'language' => 'en',
        'forced' => true,
        'hearing_impaired' => false,
        'path' => '/media/private/movie.en.srt',
    ]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'http://bazarr.test/api/episodes/subtitles'
        && $request->data()['seriesid'] === 7
        && $request->data()['path'] === '/media/private/episode.sv.srt');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'http://bazarr.test/api/movies/subtitles'
        && $request->data()['radarrid'] === 84
        && $request->data()['path'] === '/media/private/movie.en.srt');
});

test('subtitle tools tasks and media actions use exact Bazarr endpoints', function (): void {
    Http::fake([
        'bazarr.test/api/subtitles' => Http::response('', 204),
        'bazarr.test/api/system/tasks' => Http::response('', 204),
        'bazarr.test/api/series' => Http::response('', 204),
        'bazarr.test/api/movies' => Http::response('', 204),
    ]);

    $this->client->applySubtitleTool([
        'action' => 'sync',
        'language' => 'sv',
        'path' => '/media/private/episode.sv.srt',
        'media_type' => 'episode',
        'media_id' => 42,
        'forced' => false,
        'hearing_impaired' => true,
        'reference' => 'a:0',
    ]);
    $this->client->runTask('wanted_search_missing_subtitles');
    $this->client->runMediaAction('episode', 7, 'scan-disk');
    $this->client->runMediaAction('movie', 84, 'search-missing');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && $request->url() === 'http://bazarr.test/api/subtitles'
        && $request->data()['type'] === 'episode'
        && $request->data()['id'] === 42
        && $request->data()['reference'] === 'a:0');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://bazarr.test/api/system/tasks'
        && $request->data() === ['taskid' => 'wanted_search_missing_subtitles']);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && $request->url() === 'http://bazarr.test/api/series'
        && $request->data() === ['seriesid' => 7, 'action' => 'scan-disk']);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && $request->url() === 'http://bazarr.test/api/movies'
        && $request->data() === ['radarrid' => 84, 'action' => 'search-missing']);
});

test('case-sensitive subtitle tool actions reach Bazarr unchanged', function (string $action): void {
    Http::fake(['bazarr.test/api/subtitles' => Http::response('', 204)]);

    // Bazarr's own action names are case-sensitive, and these are the values the
    // drawer submits and OperationRequest approves.
    $this->client->applySubtitleTool([
        'action' => $action,
        'language' => 'en',
        'path' => '/media/private/movie.en.srt',
        'media_type' => 'movie',
        'media_id' => 84,
        'forced' => false,
        'hearing_impaired' => false,
    ]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && $request->url() === 'http://bazarr.test/api/subtitles'
        && $request->data()['action'] === $action);
})->with(['remove_HI', 'OCR_fixes', 'remove_tags', 'fix_uppercase']);

test('an action outside the allowlist is refused before any request', function (): void {
    Http::preventStrayRequests();

    expect(fn (): mixed => $this->client->applySubtitleTool([
        'action' => 'drop_database',
        'language' => 'en',
        'path' => '/media/private/movie.en.srt',
        'media_type' => 'movie',
        'media_id' => 84,
    ]))->toThrow(InvalidArgumentException::class, 'subtitle action is invalid');
});

test('writes are never retried after connection loss', function (): void {
    Http::fake([
        'bazarr.test/api/system/tasks' => Http::sequence()
            ->pushFailedConnection()
            ->push('', 204),
    ]);

    expect(fn () => $this->client->runTask('wanted_search_missing_subtitles'))
        ->toThrow(ConnectionException::class);

    Http::assertSentCount(1);
});

test('write conflicts are surfaced to callers', function (): void {
    Http::fake(['bazarr.test/api/movies/subtitles' => Http::response('Conflict', 409)]);

    expect(fn () => $this->client->downloadBestMovie(84, 'en', false, false))
        ->toThrow(RequestException::class);

    Http::assertSentCount(1);
});
