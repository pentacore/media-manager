<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    ServiceConnection::factory()->prowlarr()->create(['url' => 'http://prowlarr.local:9696', 'api_key' => 'prowlarr-secret-key']);

    Http::fake([
        'prowlarr.local:9696/api/v1/search*' => fn (Request $request) => $request->method() === 'POST'
            ? Http::response(['title' => 'Severance.S02E07.1080p.WEB-DL'], 200)
            : Http::response([[
                'guid' => 'https://tracker.example/download/1?passkey=tracker-passkey',
                'indexerId' => 3,
                'title' => 'Severance.S02E07.1080p.WEB-DL',
                'indexer' => 'NZBgeek',
                'size' => 2_500_000_000,
                'seeders' => 412,
                'age' => 1,
                'publishDate' => now()->subDay()->toIso8601String(),
                'downloadUrl' => 'http://prowlarr.local:9696/3/download?apikey=prowlarr-secret-key&link=abc',
                'infoUrl' => 'https://tracker.example/details/1?passkey=tracker-passkey',
            ]]),
    ]);
});

test('an admin grabs a release from the indexer search', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $key = hash('sha256', 'https://tracker.example/download/1?passkey=tracker-passkey');

    visit(route('prowlarr.search', ['q' => 'severance'], absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn("[data-prowlarr-release=\"{$key}\"]", 'NZBgeek')
        ->click("[data-prowlarr-release=\"{$key}\"] [data-prowlarr-grab]")
        ->assertSee('Sent "Severance.S02E07.1080p.WEB-DL" to the download client.')
        ->assertMissing('a[href*="apikey"]')
        ->assertMissing('a[href*="passkey"]')
        ->assertNoSmoke();

    // Checks the URL before indexing the body: with SSR enabled, Inertia's own
    // render POST is also recorded by Http::fake() and lacks an `indexerId`
    // key, which would otherwise throw before short-circuiting.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/v1/search')
        && $request['indexerId'] === 3);
});

test('members find the indexer search in the sidebar but get no grab button', function (): void {
    $this->actingAs(User::factory()->member()->create());

    visit(route('prowlarr.search', ['q' => 'severance'], absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-sidebar="content"]', 'Indexer search')
        ->assertSeeIn('[data-prowlarr-results]', 'NZBgeek')
        ->assertMissing('[data-prowlarr-grab]');
});
