<?php

declare(strict_types=1);

use App\Models\ActionRequest;
use App\Models\ServiceConnection;
use App\Services\Radarr\RadarrActions;
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
