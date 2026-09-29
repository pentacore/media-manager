<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    $this->member = User::factory()->member()->create();
});

test('viewer cannot reach the page', function (): void {
    $viewer = User::factory()->create(); // default Viewer

    $this->actingAs($viewer)->get('/prowlarr/search')->assertForbidden();
});

test('renders the page with empty results when no query is provided', function (): void {
    ServiceConnection::factory()->prowlarr()->create([
        'url' => 'http://prowlarr.local:9696',
        'api_key' => 'test',
    ]);

    $this->actingAs($this->member)
        ->get('/prowlarr/search')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
            ->component('Prowlarr/Search')
            ->where('query', '')
            ->where('results', [])
            ->where('hasConnection', true));
});

test('renders results when a query is provided', function (): void {
    $connection = ServiceConnection::factory()->prowlarr()->create([
        'url' => 'http://prowlarr.local:9696',
        'api_key' => 'test',
    ]);

    Http::fake([
        'prowlarr.local:9696/api/v1/search*' => Http::response([
            ['title' => 'Demo.S01E01.1080p', 'indexer' => 'Demo', 'size' => 1_000_000_000, 'seeders' => 10, 'age' => 1, 'downloadUrl' => 'http://demo/foo', 'publishDate' => '2026-04-20T00:00:00Z'],
        ]),
    ]);

    $this->actingAs($this->member)
        ->get('/prowlarr/search?q=Demo')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
            ->component('Prowlarr/Search')
            ->where('query', 'Demo')
            ->has('results', 1)
            ->where('results.0.title', 'Demo.S01E01.1080p'));
});

test('renders empty results and a friendly error if no Prowlarr connection is configured', function (): void {
    $this->actingAs($this->member)
        ->get('/prowlarr/search?q=Demo')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
            ->component('Prowlarr/Search')
            ->where('hasConnection', false)
            ->where('results', []));
});

test('renders an error message when the Prowlarr client throws', function (): void {
    ServiceConnection::factory()->prowlarr()->create([
        'url' => 'http://prowlarr.local:9696',
        'api_key' => 'test',
    ]);

    Http::fake([
        'prowlarr.local:9696/api/v1/search*' => Http::response([], 500),
    ]);

    $this->actingAs($this->member)
        ->get('/prowlarr/search?q=Demo')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
            ->component('Prowlarr/Search')
            ->where('error', 'Indexer search failed.')
            ->where('results', []));
});

test('results never carry Prowlarr download links or other credential-bearing fields', function (): void {
    ServiceConnection::factory()->prowlarr()->create([
        'url' => 'http://prowlarr.local:9696',
        'api_key' => 'prowlarr-secret-key',
    ]);

    Http::fake([
        'prowlarr.local:9696/api/v1/search*' => Http::response([[
            'guid' => 'https://tracker.example/download/1?passkey=tracker-passkey',
            'title' => 'Demo.S01E01.1080p',
            'indexer' => 'Demo',
            'indexerId' => 3,
            'size' => 1_000_000_000,
            'seeders' => 10,
            'age' => 1,
            'publishDate' => '2026-04-20T00:00:00Z',
            'downloadUrl' => 'http://prowlarr.local:9696/3/download?apikey=prowlarr-secret-key&link=abc',
            'magnetUrl' => 'magnet:?xt=urn:btih:abc&tr=https://tracker.example/announce?passkey=tracker-passkey',
        ]]),
    ]);

    $response = $this->actingAs($this->member)->get('/prowlarr/search?q=Demo');

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
            ->where('results.0', [
                'title' => 'Demo.S01E01.1080p',
                'indexer' => 'Demo',
                'size' => 1_000_000_000,
                'seeders' => 10,
                'age' => 1,
                'publishDate' => '2026-04-20T00:00:00Z',
            ]));

    expect($response->getContent())
        ->not->toContain('prowlarr-secret-key')
        ->not->toContain('tracker-passkey');
});
