<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Enums\BazarrServiceRole;
use App\Models\ActionRequest;
use App\Models\BazarrServiceLink;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Bazarr\BazarrCandidateFingerprint;
use App\Services\Bazarr\BazarrMediaFingerprint;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('o', 32)));
    Http::preventStrayRequests();
    $this->seed(ActionTypeConfigSeeder::class);

    $this->bazarr = ServiceConnection::factory()->bazarr()->create([
        'url' => 'http://bazarr.test',
        'api_key' => 'bazarr-secret',
    ]);
    $this->radarr = ServiceConnection::factory()->radarr()->create();
    BazarrServiceLink::factory()->create([
        'bazarr_connection_id' => $this->bazarr->id,
        'related_connection_id' => $this->radarr->id,
        'role' => BazarrServiceRole::Radarr,
    ]);
    $this->rawMovie = [
        'radarrId' => 801,
        'title' => 'Example Movie',
        'sceneName' => 'Example.Movie.2024.1080p',
        'path' => '/media/movies/Example Movie (2024)',
        'monitored' => true,
        'subtitles' => [[
            'name' => 'English',
            'code2' => 'en',
            'code3' => 'eng',
            'path' => '/media/movies/Example Movie (2024)/Example.Movie.en.srt',
            'forced' => false,
            'hi' => false,
        ]],
    ];
    $this->rawCandidate = [
        'provider' => 'AnimeTosho',
        'subtitle' => 'private-provider-id',
        'url' => 'https://provider.test/private-download',
        'language' => 'swe',
        'forced' => false,
        'hearing_impaired' => false,
        'score' => 98,
        'release_info' => ['Example.Movie.2024.1080p'],
        'original_format' => false,
    ];

    // Read at request time so a test can narrow what this Bazarr advertises.
    $this->swaggerPaths = [
        '/providers/episodes' => [
            'get' => ['responses' => ['200' => ['description' => 'OK']]],
            'post' => ['responses' => ['204' => ['description' => 'OK']]],
        ],
        '/providers/movies' => [
            'get' => ['responses' => ['200' => ['description' => 'OK']]],
            'post' => ['responses' => ['204' => ['description' => 'OK']]],
        ],
        '/movies/subtitles' => [
            'patch' => ['responses' => ['204' => ['description' => 'OK']]],
            'delete' => ['responses' => ['204' => ['description' => 'OK']]],
        ],
        '/episodes/subtitles' => [
            'patch' => ['responses' => ['204' => ['description' => 'OK']]],
            'delete' => ['responses' => ['204' => ['description' => 'OK']]],
        ],
        '/subtitles' => [
            'patch' => ['responses' => ['204' => ['description' => 'OK']]],
        ],
    ];

    Http::fake(function (Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match ($path) {
            '/api/movies' => Http::response(['data' => [$this->rawMovie], 'total' => 1]),
            '/api/movies/history' => Http::response(['data' => [], 'total' => 0]),
            '/api/providers/movies' => Http::response(['data' => [$this->rawCandidate]]),
            '/api/swagger.json' => Http::response([
                'swagger' => '2.0',
                'basePath' => '/api',
                'info' => ['title' => 'Bazarr', 'version' => '1.6.0'],
                'paths' => $this->swaggerPaths,
            ]),
            default => Http::response(['data' => []]),
        };
    });
});

test('viewers cannot search or request subtitle operations', function (): void {
    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->getJson(route('bazarr.search', [
            'connection' => $this->bazarr->id,
            'media_type' => 'movie',
            'media_id' => 801,
            'target_fingerprint' => hash('sha256', 'target'),
        ]))
        ->assertForbidden();

    $this->actingAs($viewer)
        ->postJson(route('bazarr.operations.store'), [
            'operation' => 'download_best',
            'connection' => $this->bazarr->id,
            'media_type' => 'movie',
            'media_id' => 801,
            'target_fingerprint' => hash('sha256', 'target'),
            'language' => 'swe',
            'forced' => false,
            'hearing_impaired' => false,
        ])
        ->assertForbidden();
});

test('members receive bounded sanitized candidates and a freshly inspected item', function (): void {
    $targetFingerprint = new BazarrMediaFingerprint()->make('movie', $this->rawMovie);

    $response = $this->actingAs(User::factory()->member()->create())
        ->getJson(route('bazarr.search', [
            'connection' => $this->bazarr->id,
            'media_type' => 'movie',
            'media_id' => 801,
            'target_fingerprint' => $targetFingerprint,
        ]))
        ->assertOk()
        ->assertJsonPath('item.media_id', 801)
        ->assertJsonPath('item.target_fingerprint', $targetFingerprint)
        ->assertJsonPath('candidates.0.provider', 'AnimeTosho')
        ->assertJsonPath('capabilities.manual_search', true)
        ->assertJsonCount(1, 'candidates');

    expect($response->getContent())
        ->not->toContain('private-provider-id')
        ->not->toContain('provider.test/private-download')
        ->not->toContain('/media/movies');
});

test('raw browser paths and provider fields are prohibited', function (string $field, string $value): void {
    $targetFingerprint = new BazarrMediaFingerprint()->make('movie', $this->rawMovie);

    $this->actingAs(User::factory()->member()->create())
        ->postJson(route('bazarr.operations.store'), [
            'operation' => 'download_best',
            'connection' => $this->bazarr->id,
            'media_type' => 'movie',
            'media_id' => 801,
            'target_fingerprint' => $targetFingerprint,
            'language' => 'swe',
            'forced' => false,
            'hearing_impaired' => false,
            $field => $value,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(ActionRequest::query()->count())->toBe(0);
})->with([
    ['path', '/browser/injected.srt'],
    ['url', 'https://attacker.test/subtitle'],
    ['subtitle', 'raw-provider-id'],
    ['provider', 'InjectedProvider'],
]);

test('operation validation rejects unsupported names and malformed selectors', function (): void {
    $this->actingAs(User::factory()->member()->create())
        ->postJson(route('bazarr.operations.store'), [
            'operation' => 'arbitrary_write',
            'connection' => $this->bazarr->id,
            'media_type' => 'series',
            'media_id' => 0,
            'target_fingerprint' => 'not-a-fingerprint',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'operation',
            'media_type',
            'media_id',
            'target_fingerprint',
        ]);
});

test('members create an approval-gated exact download from a fresh server-side candidate', function (): void {
    $targetFingerprint = new BazarrMediaFingerprint()->make('movie', $this->rawMovie);
    $candidateFingerprint = new BazarrCandidateFingerprint()->make([
        'media_type' => 'movie',
        'media_id' => 801,
        ...array_diff_key($this->rawCandidate, array_flip(['url', 'original_format'])),
    ]);

    $this->actingAs(User::factory()->member()->create())
        ->postJson(route('bazarr.operations.store'), [
            'operation' => 'download_exact',
            'connection' => $this->bazarr->id,
            'media_type' => 'movie',
            'media_id' => 801,
            'target_fingerprint' => $targetFingerprint,
            'candidate_fingerprint' => $candidateFingerprint,
        ])
        ->assertCreated()
        ->assertJsonPath('status', ActionRequestStatus::Pending->value)
        ->assertJsonPath('type', 'bazarr_download_exact');

    $actionRequest = ActionRequest::query()->sole();

    expect($actionRequest->payload)->toBe([
        'title' => 'Download a selected subtitle for Example Movie',
        'bazarr_connection_id' => $this->bazarr->id,
        'service_connection_id' => $this->radarr->id,
        'media_type' => 'movie',
        'target_ids' => ['radarr_id' => 801],
        'target_fingerprint' => $targetFingerprint,
        'candidate_fingerprint' => $candidateFingerprint,
    ])->and($actionRequest->status)->toBe(ActionRequestStatus::Pending);
});

test('members read Bazarr capabilities without running a manual search', function (): void {
    $this->actingAs(User::factory()->member()->create())
        ->getJson(route('bazarr.capabilities', ['connection' => $this->bazarr->id]))
        ->assertOk()
        ->assertJsonPath('capabilities.manual_search', true)
        ->assertJsonPath('capabilities.sync', true)
        ->assertJsonPath('capabilities.upload', false);

    Http::assertNotSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/providers/movies');
});

test('viewers cannot read Bazarr capabilities', function (): void {
    $this->actingAs(User::factory()->create())
        ->getJson(route('bazarr.capabilities', ['connection' => $this->bazarr->id]))
        ->assertForbidden();
});

test('an operation this Bazarr cannot perform is refused server side', function (): void {
    // This Bazarr exposes nothing but the movie list, so no write capability is
    // available; a queued request could auto-execute, so the server is the gate.
    $this->swaggerPaths = [
        '/movies' => ['get' => ['responses' => ['200' => ['description' => 'OK']]]],
    ];

    $this->actingAs(User::factory()->member()->create())
        ->postJson(route('bazarr.operations.store'), [
            'operation' => 'download_best',
            'connection' => $this->bazarr->id,
            'media_type' => 'movie',
            'media_id' => 801,
            'target_fingerprint' => new BazarrMediaFingerprint()->make('movie', $this->rawMovie),
            'language' => 'swe',
            'forced' => false,
            'hearing_impaired' => false,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('operation');

    expect(ActionRequest::query()->count())->toBe(0);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH');
});

test('a manual search is refused when Bazarr has no manual search endpoint', function (): void {
    $this->swaggerPaths = [
        '/movies' => ['get' => ['responses' => ['200' => ['description' => 'OK']]]],
    ];

    $this->actingAs(User::factory()->member()->create())
        ->getJson(route('bazarr.search', [
            'connection' => $this->bazarr->id,
            'media_type' => 'movie',
            'media_id' => 801,
            'target_fingerprint' => new BazarrMediaFingerprint()->make('movie', $this->rawMovie),
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('connection');

    Http::assertNotSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/providers/movies');
});

test('stale media and candidate fingerprints cannot create an action', function (string $field): void {
    $targetFingerprint = new BazarrMediaFingerprint()->make('movie', $this->rawMovie);
    $candidateFingerprint = new BazarrCandidateFingerprint()->make([
        'media_type' => 'movie',
        'media_id' => 801,
        ...array_diff_key($this->rawCandidate, array_flip(['url', 'original_format'])),
    ]);

    $payload = [
        'operation' => 'download_exact',
        'connection' => $this->bazarr->id,
        'media_type' => 'movie',
        'media_id' => 801,
        'target_fingerprint' => $targetFingerprint,
        'candidate_fingerprint' => $candidateFingerprint,
    ];
    $payload[$field] = hash('sha256', 'stale');

    $this->actingAs(User::factory()->member()->create())
        ->postJson(route('bazarr.operations.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(ActionRequest::query()->count())->toBe(0);
})->with(['target_fingerprint', 'candidate_fingerprint']);
