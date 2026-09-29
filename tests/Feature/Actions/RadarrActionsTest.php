<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\MediaReplacement\ReplacementInFlight;
use App\Services\Radarr\RadarrActions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878',
        'api_key' => 'k',
    ]);
});

test('deleteMovie sends DELETE to radarr with deleteFiles flag', function (): void {
    Http::fake(['radarr.local:7878/api/v3/movie/99*' => Http::response(null, 200)]);

    $request = ActionRequest::factory()->create([
        'type' => 'delete_movie',
        'payload' => ['radarr_movie_id' => 99, 'delete_files' => true],
    ]);

    $result = (new RadarrActions)->execute($request);

    expect($result)->toMatchArray(['radarr_movie_id' => 99, 'delete_files' => true]);

    Http::assertSent(fn ($r): bool => $r->method() === 'DELETE'
        && str_contains((string) $r->url(), '/api/v3/movie/99')
        && str_contains((string) $r->url(), 'deleteFiles=true'));
});

test('deleteMovie throws when radarr_movie_id is missing', function (): void {
    $request = ActionRequest::factory()->create(['type' => 'delete_movie', 'payload' => []]);

    expect(fn (): array => (new RadarrActions)->execute($request))->toThrow(InvalidArgumentException::class);
});

test('execute throws for unknown type', function (): void {
    $request = ActionRequest::factory()->create(['type' => 'zzz', 'payload' => []]);

    expect(fn (): array => (new RadarrActions)->execute($request))->toThrow(InvalidArgumentException::class);
});

test('monitor_movie is refused while a replacement for the movie is in flight', function (): void {
    ActionRequest::factory()->create([
        'type' => 'replace_media_file',
        'status' => ActionRequestStatus::Pending,
        'payload' => ['target' => ['service' => 'radarr', 'service_connection_id' => ServiceConnection::query()->firstOrFail()->id, 'movie_id' => 10]],
    ]);

    expect(fn (): array => resolve(RadarrActions::class)->execute(ActionRequest::factory()->create([
        'type' => 'monitor_movie',
        'payload' => ['movie_id' => 10, 'monitored' => false],
    ])))->toThrow(ReplacementInFlight::class);

    Http::assertNothingSent();
});

test('search_media runs the matching Radarr command', function (string $command, array $payload, array $body): void {
    Http::fake(['radarr.local:7878/api/v3/command' => Http::response(['id' => 9], 201)]);

    (new RadarrActions)->execute(ActionRequest::factory()->create([
        'type' => 'search_media',
        'payload' => ['service' => 'radarr', 'command' => $command, ...$payload],
    ]));

    Http::assertSent(fn (Request $request): bool => $request->data() === $body);
})->with([
    'movie' => ['movies_search', ['movie_ids' => [10]], ['name' => 'MoviesSearch', 'movieIds' => [10]]],
    'all missing' => ['missing_movies_search', [], ['name' => 'MissingMoviesSearch']],
    'all cutoff unmet' => ['cutoff_unmet_movies_search', [], ['name' => 'CutoffUnmetMoviesSearch']],
]);

test('grab_release posts the release to Radarr', function (): void {
    Http::fake(['radarr.local:7878/api/v3/release' => Http::response([], 200)]);

    (new RadarrActions)->execute(ActionRequest::factory()->create([
        'type' => 'grab_release',
        'payload' => ['service' => 'radarr', 'movie_id' => 10, 'guid' => 'g-2', 'indexer_id' => 4, 'release' => ['title' => 'Movie.2026.1080p']],
    ]));

    Http::assertSent(fn (Request $request): bool => $request->data() === ['guid' => 'g-2', 'indexerId' => 4]);
});
