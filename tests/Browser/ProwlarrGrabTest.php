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
        ->assertSeeIn("[data-prowlarr-release=\"3:{$key}\"]", 'NZBgeek')
        ->click("[data-prowlarr-release=\"3:{$key}\"] [data-prowlarr-grab]")
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

test('the same release from two indexers renders as two rows and grabs from the one clicked', function (): void {
    // A distinct host so this fake wins outright over the beforeEach's
    // prowlarr.local stub (Http::fake matches the first-registered pattern).
    ServiceConnection::query()->where('type', 'prowlarr')->update(['url' => 'http://prowlarr-dupe.local:9696']);
    $release = fn (int $indexerId, string $indexer): array => [
        'guid' => 'https://tracker.example/download/1',
        'indexerId' => $indexerId,
        'title' => 'Severance.S02E07.1080p.WEB-DL',
        'indexer' => $indexer,
        'size' => 2_500_000_000,
        'publishDate' => now()->subDay()->toIso8601String(),
    ];
    Http::fake([
        'prowlarr-dupe.local:9696/api/v1/search*' => fn (Request $request) => $request->method() === 'POST'
            ? Http::response(['title' => 'Severance.S02E07.1080p.WEB-DL'], 200)
            : Http::response([$release(3, 'NZBgeek'), $release(4, 'DrunkenSlug')]),
    ]);
    $this->actingAs(User::factory()->admin()->create());
    $key = hash('sha256', 'https://tracker.example/download/1');

    visit(route('prowlarr.search', ['q' => 'severance'], absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn("[data-prowlarr-release=\"3:{$key}\"]", 'NZBgeek')
        ->assertSeeIn("[data-prowlarr-release=\"4:{$key}\"]", 'DrunkenSlug')
        ->click("[data-prowlarr-release=\"4:{$key}\"] [data-prowlarr-grab]")
        ->assertSee('Sent "Severance.S02E07.1080p.WEB-DL" to the download client.')
        ->assertNoSmoke();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/v1/search')
        && $request['indexerId'] === 4);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/v1/search')
        && $request['indexerId'] === 3);
});
