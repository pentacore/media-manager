<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Seerr\CleanupRequestTool;
use App\Ai\Tools\Seerr\DiscoverMoviesTool;
use App\Ai\Tools\Seerr\DiscoverTvTool;
use App\Ai\Tools\Seerr\GetTitleTool;
use App\Ai\Tools\Seerr\ListPendingRequestsTool;
use App\Ai\Tools\Seerr\SearchCatalogTool;
use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->connection = ServiceConnection::factory()->seerr()->create([
        'url' => 'http://seerr.local:5055',
        'api_key' => 'test',
        'is_active' => true,
    ]);
});

// SearchCatalogTool

test('SearchCatalogTool searches the Seerr catalog by query', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/search*' => Http::response([
            'results' => [['id' => 1, 'mediaType' => 'movie', 'title' => 'Inception']],
        ]),
    ]);

    $result = json_decode((string) (new SearchCatalogTool)->handle(new Request(['query' => 'inception'])), true);

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'query=inception'));
    expect($result['results'])->toHaveCount(1);
});

test('SearchCatalogTool risk is Read', function (): void {
    expect((new SearchCatalogTool)->risk())->toBe(Risk::Read);
});

// DiscoverMoviesTool

test('DiscoverMoviesTool calls /discover/movies with non-null filters', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/discover/movies*' => Http::response([
            'results' => [['id' => 603, 'title' => 'The Matrix']],
        ]),
    ]);

    $result = json_decode((string) (new DiscoverMoviesTool)->handle(new Request([
        'genre' => '28',
        'sort_by' => 'popularity.desc',
        'page' => 1,
    ])), true);

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/api/v1/discover/movies')
        && str_contains((string) $request->url(), 'genre=28')
        && str_contains((string) $request->url(), 'sortBy=popularity.desc')
        && str_contains((string) $request->url(), 'page=1'));

    expect($result['results'])->toHaveCount(1);
});

test('DiscoverMoviesTool drops null filters before calling client', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/discover/movies*' => Http::response(['results' => []]),
    ]);

    (new DiscoverMoviesTool)->handle(new Request([
        'genre' => null,
        'sort_by' => null,
        'page' => null,
    ]));

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/api/v1/discover/movies')
        && ! str_contains((string) $request->url(), 'genre=')
        && ! str_contains((string) $request->url(), 'sortBy=')
        && ! str_contains((string) $request->url(), 'page='));
});

test('DiscoverMoviesTool risk is Read', function (): void {
    expect((new DiscoverMoviesTool)->risk())->toBe(Risk::Read);
});

// DiscoverTvTool

test('DiscoverTvTool calls /discover/tv with non-null filters', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/discover/tv*' => Http::response([
            'results' => [['id' => 1396, 'name' => 'Breaking Bad']],
        ]),
    ]);

    $result = json_decode((string) (new DiscoverTvTool)->handle(new Request([
        'genre' => '18',
        'sort_by' => 'vote_average.desc',
        'page' => 2,
    ])), true);

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/api/v1/discover/tv')
        && str_contains((string) $request->url(), 'genre=18')
        && str_contains((string) $request->url(), 'sortBy=vote_average.desc')
        && str_contains((string) $request->url(), 'page=2'));

    expect($result['results'])->toHaveCount(1);
});

test('DiscoverTvTool risk is Read', function (): void {
    expect((new DiscoverTvTool)->risk())->toBe(Risk::Read);
});

// GetTitleTool

test('GetTitleTool fetches movie details when media_type is movie', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/movie/603' => Http::response(['id' => 603, 'title' => 'The Matrix']),
    ]);

    $result = json_decode((string) (new GetTitleTool)->handle(new Request([
        'tmdb_id' => 603,
        'media_type' => 'movie',
    ])), true);

    expect($result['title'])->toBe('The Matrix');
});

test('GetTitleTool fetches tv details when media_type is tv', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/tv/1396' => Http::response(['id' => 1396, 'name' => 'Breaking Bad']),
    ]);

    $result = json_decode((string) (new GetTitleTool)->handle(new Request([
        'tmdb_id' => 1396,
        'media_type' => 'tv',
    ])), true);

    expect($result['name'])->toBe('Breaking Bad');
});

test('GetTitleTool returns tool_failed for unknown media_type', function (): void {
    $result = json_decode((string) (new GetTitleTool)->handle(new Request([
        'tmdb_id' => 1,
        'media_type' => 'bogus',
    ])), true);

    expect($result['error'])->toBe('tool_failed');
});

test('GetTitleTool risk is Read', function (): void {
    expect((new GetTitleTool)->risk())->toBe(Risk::Read);
});

// ListPendingRequestsTool

test('ListPendingRequestsTool calls /request with filter=pending', function (): void {
    Http::fake([
        'seerr.local:5055/api/v1/request*' => Http::response([
            'results' => [['id' => 1, 'status' => 1]],
        ]),
    ]);

    $result = json_decode((string) (new ListPendingRequestsTool)->handle(new Request([])), true);

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/api/v1/request')
        && str_contains((string) $request->url(), 'filter=pending'));

    expect($result['results'])->toHaveCount(1);
});

test('ListPendingRequestsTool risk is Read', function (): void {
    expect((new ListPendingRequestsTool)->risk())->toBe(Risk::Read);
});

// CleanupRequestTool

test('CleanupRequestTool queues a cleanup_seerr_request ActionRequest', function (): void {
    ActionTypeConfig::factory()->create([
        'type' => 'cleanup_seerr_request',
        'is_enabled' => true,
        'requires_approval' => true,
    ]);

    $result = json_decode((string) (new CleanupRequestTool)->handle(new Request([
        'seerr_request_id' => 99,
    ])), true);

    expect($result['queued'])->toBeTrue();
    expect($result['status'])->toBe(ActionRequestStatus::Pending->value);
    expect($result['requires_approval'])->toBeTrue();

    $ar = ActionRequest::firstWhere('type', 'cleanup_seerr_request');
    expect($ar->target_service)->toBe('seerr');
    expect($ar->payload)->toEqual(['seerr_request_id' => 99]);
});

test('CleanupRequestTool reports no_action_type_config when rule is missing', function (): void {
    $result = json_decode((string) (new CleanupRequestTool)->handle(new Request([
        'seerr_request_id' => 99,
    ])), true);

    expect($result['queued'])->toBeFalse();
    expect($result['reason'])->toBe('no_action_type_config');
});

test('CleanupRequestTool risk is Destructive', function (): void {
    expect((new CleanupRequestTool)->risk())->toBe(Risk::Destructive);
});
