<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('a viewer finds an unrequested movie in search and requests it from the sheet', function (): void {
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
    Http::fake([
        'seerr.local:5055/api/v1/search*' => Http::response(['results' => [
            ['id' => 438631, 'mediaType' => 'movie', 'title' => 'Dune', 'releaseDate' => '2021-10-22', 'posterPath' => '/poster.jpg'],
        ]]),
        'seerr.local:5055/api/v1/user*' => Http::response(['pageInfo' => ['pages' => 1, 'page' => 1], 'results' => [['id' => 7, 'email' => 'viewer@example.com']]]),
        'seerr.local:5055/api/v1/movie/438631' => Http::response(['id' => 438631, 'title' => 'Dune', 'releaseDate' => '2021-10-22', 'runtime' => 155, 'overview' => 'Spice.']),
        'seerr.local:5055/api/v1/request' => Http::response(['id' => 5, 'status' => 1], 201),
    ]);

    $this->actingAs(User::factory()->create(['email' => 'viewer@example.com']));

    visit(route('media.search.index', ['q' => 'dune'], absolute: false))
        ->assertNoSmoke()
        ->click('[data-search-title="movie-438631"]')
        ->assertSeeIn('[data-title-sheet]', 'Spice.')
        ->click('[data-request-submit]')
        ->assertSee('Request submitted.')
        ->assertNoSmoke();
});
