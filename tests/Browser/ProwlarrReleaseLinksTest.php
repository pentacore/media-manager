<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Prowlarr's downloadUrl embeds its API key and a release guid can carry a
 * tracker passkey, so neither indexer page may render them.
 */
beforeEach(function (): void {
    ServiceConnection::factory()->prowlarr()->create([
        'url' => 'http://prowlarr.local:9696',
        'api_key' => 'prowlarr-secret-key',
    ]);

    Http::fake([
        'prowlarr.local:9696/api/v1/search*' => Http::response([[
            'guid' => 'https://tracker.example/download/1?passkey=tracker-passkey',
            'title' => 'Severance.S02E07.1080p.WEB-DL',
            'indexer' => 'ETTV',
            'size' => 2_500_000_000,
            'seeders' => 412,
            'age' => 1,
            'publishDate' => now()->subDay()->toIso8601String(),
            'downloadUrl' => 'http://prowlarr.local:9696/3/download?apikey=prowlarr-secret-key&link=abc',
            'infoUrl' => 'https://tracker.example/details/1',
        ]]),
    ]);

    $this->actingAs(User::factory()->member()->create());
});

test('the indexer search page lists releases without download links', function (): void {
    visit(route('prowlarr.search', ['q' => 'severance'], absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-prowlarr-results]', 'Severance.S02E07.1080p.WEB-DL')
        ->assertMissing('a[href*="apikey"]')
        ->assertMissing('a[href*="passkey"]');
});

test('the unified search indexer scope lists releases without download links', function (): void {
    visit(route('media.search.index', ['q' => 'severance', 'scope' => 'indexers'], absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-indexer-results]', 'Severance.S02E07.1080p.WEB-DL')
        ->assertMissing('a[href*="apikey"]')
        ->assertMissing('a[href*="passkey"]');
});
