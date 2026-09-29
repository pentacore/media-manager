<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('a viewer lists their requests and cancels the pending one', function (): void {
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
    Http::fake([
        'seerr.local:5055/api/v1/user*' => Http::response(['pageInfo' => ['pages' => 1, 'page' => 1], 'results' => [['id' => 7, 'email' => 'viewer@example.com']]]),
        'seerr.local:5055/api/v1/request/41' => fn (Request $request) => $request->method() === 'DELETE'
            ? Http::response(null, 204)
            : Http::response(['id' => 41, 'status' => 1, 'requestedBy' => ['id' => 7]]),
        'seerr.local:5055/api/v1/request*' => Http::response(['pageInfo' => ['pages' => 1, 'page' => 1, 'results' => 2, 'pageSize' => 20], 'results' => [
            ['id' => 41, 'status' => 1, 'type' => 'movie', 'media' => ['tmdbId' => 1, 'mediaType' => 'movie', 'status' => 2], 'requestedBy' => ['id' => 7]],
            ['id' => 42, 'status' => 2, 'type' => 'tv', 'media' => ['tmdbId' => 2, 'mediaType' => 'tv', 'status' => 3], 'requestedBy' => ['id' => 7]],
        ]]),
        // posterPath keeps each row an <img> instead of Poster's text-hint
        // fallback, which would otherwise duplicate the title in the same
        // row and break strict-mode assertSeeIn (see DiscoverTest.php).
        'seerr.local:5055/api/v1/movie/1' => Http::response(['id' => 1, 'title' => 'Dune', 'posterPath' => '/dune.jpg']),
        'seerr.local:5055/api/v1/tv/2' => Http::response(['id' => 2, 'name' => 'Severance', 'posterPath' => '/severance.jpg']),
    ]);

    $this->actingAs(User::factory()->create(['email' => 'viewer@example.com']));

    visit(route('media.requests.mine', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-my-request="41"]', 'Dune')
        ->assertSeeIn('[data-my-request="42"]', 'Severance')
        ->assertCount('[data-cancel-request="42"]', 0)
        ->click('[data-cancel-request="41"]')
        ->click('[data-cancel-confirm]')
        ->assertSee('Request cancelled.')
        ->assertNoSmoke();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
});
