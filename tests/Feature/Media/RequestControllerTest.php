<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    $this->connection = ServiceConnection::factory()->seerr()->create([
        'url' => 'http://seerr.local:5055',
        'api_key' => 'test-api-key',
    ]);
});

test('guests are redirected to login from requests index', function (): void {
    $this->get(route('media.requests.index'))->assertRedirect(route('login'));
});

test('viewers cannot access requests index', function (): void {
    $viewer = User::factory()->create();
    $this->actingAs($viewer)->get(route('media.requests.index'))->assertForbidden();
});

test('members can list requests with title enrichment and summary', function (): void {
    $member = User::factory()->member()->create();

    Http::fake([
        'seerr.local:5055/api/v1/request/count' => Http::response([
            'total' => 75,
            'pending' => 5,
            'approved' => 60,
            'declined' => 10,
            'movie' => 40,
            'tv' => 35,
        ]),
        'seerr.local:5055/api/v1/movie/603' => Http::response([
            'id' => 603,
            'title' => 'The Matrix',
        ]),
        'seerr.local:5055/api/v1/request*' => Http::response([
            'pageInfo' => ['page' => 1, 'pages' => 2, 'pageSize' => 50, 'results' => 75],
            'results' => [
                [
                    'id' => 1,
                    'status' => 1,
                    'type' => 'movie',
                    'media' => ['mediaType' => 'movie', 'tmdbId' => 603],
                    'requestedBy' => ['displayName' => 'Alice'],
                    'createdAt' => '2026-04-01T00:00:00Z',
                ],
            ],
        ]),
    ]);

    $this->actingAs($member)
        ->get(route('media.requests.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Seerr/Requests')
            ->has('connection.url')
            ->has('filters.page')
            ->loadDeferredProps('default', function ($page): void {
                $page
                    ->has('requests.data', 1)
                    ->where('requests.data.0.media_title', 'The Matrix')
                    ->where('requests.data.0.requester', 'Alice')
                    ->where('requests.meta.total', 75)
                    ->where('requests.meta.current_page', 1)
                    ->where('requests.meta.last_page', 2)
                    ->where('summary.total', 75)
                    ->where('summary.pending', 5)
                    ->where('summary.approved', 60)
                    ->where('summary.declined', 10);
            })
        );
});

test('tv requests resolve titles via /tv detail endpoint', function (): void {
    $member = User::factory()->member()->create();

    Http::fake([
        'seerr.local:5055/api/v1/tv/1396' => Http::response([
            'id' => 1396,
            'name' => 'Breaking Bad',
        ]),
        'seerr.local:5055/api/v1/request*' => Http::response([
            'pageInfo' => ['page' => 1, 'pages' => 1, 'pageSize' => 50, 'results' => 1],
            'results' => [
                [
                    'id' => 7,
                    'status' => 2,
                    'type' => 'tv',
                    'media' => ['mediaType' => 'tv', 'tmdbId' => 1396, 'tvdbId' => 81189],
                    'requestedBy' => ['username' => 'bob'],
                    'createdAt' => '2026-04-10T00:00:00Z',
                ],
            ],
        ]),
        'seerr.local:5055/api/v1/request/count' => Http::response([
            'total' => 1, 'pending' => 0, 'approved' => 1, 'declined' => 0,
        ]),
    ]);

    $this->actingAs($member)
        ->get(route('media.requests.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps('default', function ($page): void {
                $page
                    ->where('requests.data.0.media_title', 'Breaking Bad')
                    ->where('requests.data.0.media_type', 'tv')
                    ->where('requests.data.0.requester', 'bob');
            })
        );
});

test('pagination sends skip based on page query', function (): void {
    $member = User::factory()->member()->create();

    Http::fake([
        'seerr.local:5055/api/v1/request*' => Http::response([
            'pageInfo' => ['page' => 2, 'pages' => 3, 'pageSize' => 50, 'results' => 120],
            'results' => [],
        ]),
        'seerr.local:5055/api/v1/request/count' => Http::response([
            'total' => 120, 'pending' => 0, 'approved' => 120, 'declined' => 0,
        ]),
    ]);

    $this->actingAs($member)
        ->get(route('media.requests.index', ['page' => 2]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.page', 2)
            ->loadDeferredProps('default', function ($page): void {
                $page
                    ->where('requests.meta.current_page', 2)
                    ->where('requests.meta.last_page', 3)
                    ->where('requests.meta.total', 120);
            })
        );

    Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/api/v1/request')
        && str_contains((string) $request->url(), 'skip=50')
        && str_contains((string) $request->url(), 'take=50')
    );
});

test('empty results render without errors', function (): void {
    $member = User::factory()->member()->create();

    Http::fake([
        'seerr.local:5055/api/v1/request/count' => Http::response([
            'total' => 0, 'pending' => 0, 'approved' => 0, 'declined' => 0,
        ]),
        'seerr.local:5055/api/v1/request*' => Http::response([
            'pageInfo' => ['page' => 1, 'pages' => 1, 'pageSize' => 50, 'results' => 0],
            'results' => [],
        ]),
    ]);

    $this->actingAs($member)
        ->get(route('media.requests.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps('default', function ($page): void {
                $page
                    ->has('requests.data', 0)
                    ->where('requests.meta.total', 0)
                    ->where('summary.total', 0);
            })
        );
});

test('summary falls back to zeros when count endpoint fails', function (): void {
    $member = User::factory()->member()->create();

    Http::fake([
        'seerr.local:5055/api/v1/request/count' => Http::response('Server Error', 500),
        'seerr.local:5055/api/v1/request*' => Http::response([
            'pageInfo' => ['page' => 1, 'pages' => 1, 'pageSize' => 50, 'results' => 0],
            'results' => [],
        ]),
    ]);

    $this->actingAs($member)
        ->get(route('media.requests.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps('default', function ($page): void {
                $page
                    ->where('summary.total', 0)
                    ->where('summary.pending', 0)
                    ->where('summary.approved', 0)
                    ->where('summary.declined', 0);
            })
        );
});

test('summary exposes every status bucket reported by /request/count', function (): void {
    $member = User::factory()->member()->create();

    Http::fake([
        'seerr.local:5055/api/v1/request/count' => Http::response([
            'total' => 100,
            'movie' => 60,
            'tv' => 40,
            'pending' => 5,
            'approved' => 30,
            'processing' => 7,
            'available' => 48,
            'completed' => 80,
            'declined' => 10,
        ]),
        'seerr.local:5055/api/v1/request*' => Http::response([
            'pageInfo' => ['page' => 1, 'pages' => 1, 'pageSize' => 50, 'results' => 0],
            'results' => [],
        ]),
    ]);

    $this->actingAs($member)
        ->get(route('media.requests.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps('default', function ($page): void {
                $page
                    ->where('summary.total', 100)
                    ->where('summary.pending', 5)
                    ->where('summary.approved', 30)
                    ->where('summary.processing', 7)
                    ->where('summary.available', 48)
                    ->where('summary.completed', 80)
                    ->where('summary.declined', 10)
                    ->missing('summary.movie')
                    ->missing('summary.tv');
            })
        );

    // Counts come straight from /request/count now — no per-filter probing.
    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), 'filter=available')
        && str_contains((string) $request->url(), 'take=1')
    );
    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), 'filter=processing')
        && str_contains((string) $request->url(), 'take=1')
    );
});

test('available media status is bubbled to the row status field', function (): void {
    $member = User::factory()->member()->create();

    // mapRequest needs to surface media.status === 5 (AVAILABLE) so the
    // Vue-side StatusPill shows "Now available" instead of "Approved"
    // for fully-grabbed requests.
    Http::fake([
        'seerr.local:5055/api/v1/request/count' => Http::response([
            'total' => 1, 'pending' => 0, 'approved' => 1, 'declined' => 0,
        ]),
        'seerr.local:5055/api/v1/movie/700' => Http::response(['id' => 700, 'title' => 'Available Movie']),
        'seerr.local:5055/api/v1/request*' => Http::response([
            'pageInfo' => ['page' => 1, 'pages' => 1, 'pageSize' => 50, 'results' => 1],
            'results' => [
                [
                    'id' => 1,
                    'status' => 2,
                    'type' => 'movie',
                    'media' => ['mediaType' => 'movie', 'tmdbId' => 700, 'status' => 5],
                ],
            ],
        ]),
    ]);

    $this->actingAs($member)
        ->get(route('media.requests.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps('default', function ($page): void {
                $page->where('requests.data.0.status', 5);
            })
        );
});

test('processing filter passes through to Seerr filter=processing', function (): void {
    $member = User::factory()->member()->create();

    Http::fake([
        'seerr.local:5055/api/v1/request/count' => Http::response([
            'total' => 1, 'pending' => 0, 'approved' => 1, 'declined' => 0,
        ]),
        'seerr.local:5055/api/v1/movie/900' => Http::response(['id' => 900, 'title' => 'Stuck Movie']),
        'seerr.local:5055/api/v1/request*' => Http::response([
            'pageInfo' => ['page' => 1, 'pages' => 1, 'pageSize' => 50, 'results' => 1],
            'results' => [
                ['id' => 99, 'status' => 2, 'type' => 'movie', 'media' => ['mediaType' => 'movie', 'tmdbId' => 900, 'status' => 3]],
            ],
        ]),
    ]);

    $this->actingAs($member)
        ->get(route('media.requests.index', ['status' => 'processing']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps('default', function ($page): void {
                $page
                    ->has('requests.data', 1)
                    ->where('requests.data.0.id', 99)
                    ->where('requests.data.0.media_title', 'Stuck Movie');
            })
        );

    Http::assertSent(fn ($request): bool => $request->method() === 'GET'
        && str_contains((string) $request->url(), 'filter=processing')
        && str_contains((string) $request->url(), 'take=50')
    );
});

test('declined filter walks the unfiltered list and keeps only declined rows', function (): void {
    $member = User::factory()->member()->create();

    Http::fake([
        'seerr.local:5055/api/v1/request/count' => Http::response([
            'total' => 3, 'pending' => 1, 'approved' => 1, 'declined' => 1,
        ]),
        'seerr.local:5055/api/v1/movie/700' => Http::response(['id' => 700, 'title' => 'Declined Movie']),
        'seerr.local:5055/api/v1/movie/701' => Http::response(['id' => 701, 'title' => 'Pending Movie']),
        'seerr.local:5055/api/v1/movie/702' => Http::response(['id' => 702, 'title' => 'Approved Movie']),
        'seerr.local:5055/api/v1/request*' => Http::response([
            'pageInfo' => ['page' => 1, 'pages' => 1, 'pageSize' => 100, 'results' => 3],
            'results' => [
                ['id' => 11, 'status' => 1, 'type' => 'movie', 'media' => ['mediaType' => 'movie', 'tmdbId' => 701]],
                ['id' => 12, 'status' => 3, 'type' => 'movie', 'media' => ['mediaType' => 'movie', 'tmdbId' => 700]],
                ['id' => 13, 'status' => 2, 'type' => 'movie', 'media' => ['mediaType' => 'movie', 'tmdbId' => 702]],
            ],
        ]),
    ]);

    $this->actingAs($member)
        ->get(route('media.requests.index', ['status' => 'declined']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps('default', function ($page): void {
                $page
                    ->has('requests.data', 1)
                    ->where('requests.data.0.id', 12)
                    ->where('requests.data.0.status', 3)
                    ->where('requests.data.0.media_title', 'Declined Movie')
                    ->where('requests.meta.total', 1)
                    ->where('requests.meta.last_page', 1);
            })
        );

    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), 'filter=declined'));
});

test('approved filter walks the unfiltered list and keeps only approved rows', function (): void {
    $member = User::factory()->member()->create();

    Http::fake([
        'seerr.local:5055/api/v1/request/count' => Http::response([
            'total' => 2, 'pending' => 1, 'approved' => 1, 'declined' => 0,
        ]),
        'seerr.local:5055/api/v1/movie/801' => Http::response(['id' => 801, 'title' => 'Approved Movie']),
        'seerr.local:5055/api/v1/movie/802' => Http::response(['id' => 802, 'title' => 'Pending Movie']),
        'seerr.local:5055/api/v1/request*' => Http::response([
            'pageInfo' => ['page' => 1, 'pages' => 1, 'pageSize' => 100, 'results' => 2],
            'results' => [
                ['id' => 21, 'status' => 1, 'type' => 'movie', 'media' => ['mediaType' => 'movie', 'tmdbId' => 802]],
                ['id' => 22, 'status' => 2, 'type' => 'movie', 'media' => ['mediaType' => 'movie', 'tmdbId' => 801]],
            ],
        ]),
    ]);

    $this->actingAs($member)
        ->get(route('media.requests.index', ['status' => 'approved']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps('default', function ($page): void {
                $page
                    ->has('requests.data', 1)
                    ->where('requests.data.0.id', 22)
                    ->where('requests.data.0.status', 2)
                    ->where('requests.meta.total', 1);
            })
        );

    Http::assertNotSent(fn ($request): bool => str_contains((string) $request->url(), 'filter=approved'));
});

test('declined filter paginates locally using the count endpoint as the total', function (): void {
    $member = User::factory()->member()->create();

    // 60 declined rows scattered across two upstream pages of 100. Per-page=50,
    // so page 1 should hold 50 declined and page 2 the remaining 10.
    $allRows = [];
    $declinedTmdbIds = [];
    for ($i = 1; $i <= 100; $i++) {
        $tmdb = 1000 + $i;
        // First 60 hits → declined; the rest → approved.
        $statusCode = $i <= 60 ? 3 : 2;
        $allRows[] = [
            'id' => $i,
            'status' => $statusCode,
            'type' => 'movie',
            'media' => ['mediaType' => 'movie', 'tmdbId' => $tmdb],
        ];
        if ($statusCode === 3) {
            $declinedTmdbIds[] = $tmdb;
        }
    }

    $movieFakes = [];
    foreach ($declinedTmdbIds as $declinedTmdbId) {
        $movieFakes['seerr.local:5055/api/v1/movie/'.$declinedTmdbId] = Http::response(['id' => $declinedTmdbId, 'title' => 'Movie '.$declinedTmdbId]);
    }

    Http::fake(array_merge($movieFakes, [
        'seerr.local:5055/api/v1/request/count' => Http::response([
            'total' => 100, 'pending' => 0, 'approved' => 40, 'declined' => 60,
        ]),
        'seerr.local:5055/api/v1/request*' => Http::response([
            'pageInfo' => ['page' => 1, 'pages' => 1, 'pageSize' => 100, 'results' => 100],
            'results' => $allRows,
        ]),
    ]));

    $this->actingAs($member)
        ->get(route('media.requests.index', ['status' => 'declined', 'page' => 2]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps('default', function ($page): void {
                $page
                    ->has('requests.data', 10)
                    ->where('requests.meta.current_page', 2)
                    ->where('requests.meta.last_page', 2)
                    ->where('requests.meta.total', 60);
            })
        );
});

test('requests list falls back to empty when list endpoint fails', function (): void {
    $member = User::factory()->member()->create();

    Http::fake([
        'seerr.local:5055/api/v1/request/count' => Http::response([
            'total' => 10, 'pending' => 1, 'approved' => 9, 'declined' => 0,
        ]),
        'seerr.local:5055/api/v1/request*' => Http::response('Server Error', 500),
    ]);

    $this->actingAs($member)
        ->get(route('media.requests.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps('default', function ($page): void {
                $page
                    ->has('requests.data', 0)
                    ->where('requests.meta.total', 0);
            })
        );
});

test('requests index redirects when no active seerr connection', function (): void {
    $this->connection->update(['is_active' => false]);
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->get(route('media.requests.index'))
        ->assertRedirect(route('dashboard'));
});

test('members cannot delete requests', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->delete(route('media.requests.destroy', 1))
        ->assertForbidden();
});

test('admins can delete requests', function (): void {
    $admin = User::factory()->admin()->create();

    Http::fake(['seerr.local:5055/api/v1/request/42' => Http::response(null, 200)]);

    $this->actingAs($admin)
        ->delete(route('media.requests.destroy', 42))
        ->assertRedirect(route('media.requests.index'));

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_ends_with((string) $request->url(), '/api/v1/request/42')
    );
});

test('admin delete handles connection failure gracefully', function (): void {
    $admin = User::factory()->admin()->create();

    Http::fake(['seerr.local:5055/api/v1/request/42' => Http::response('Server Error', 500)]);

    $this->actingAs($admin)
        ->from(route('media.requests.index'))
        ->delete(route('media.requests.destroy', 42))
        ->assertRedirect(route('media.requests.index'));
});

test('member can approve a pending request', function (): void {
    $member = User::factory()->member()->create();

    Http::fake(['seerr.local:5055/api/v1/request/42/approve' => Http::response([], 200)]);

    $this->actingAs($member)
        ->from(route('media.requests.index'))
        ->post(route('media.requests.approve', 42))
        ->assertRedirect(route('media.requests.index'));

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/api/v1/request/42/approve')
    );
});

test('member can decline a pending request', function (): void {
    $member = User::factory()->member()->create();

    Http::fake(['seerr.local:5055/api/v1/request/42/decline' => Http::response([], 200)]);

    $this->actingAs($member)
        ->from(route('media.requests.index'))
        ->post(route('media.requests.decline', 42))
        ->assertRedirect(route('media.requests.index'));

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/api/v1/request/42/decline')
    );
});

test('admin can retry a request', function (): void {
    $admin = User::factory()->admin()->create();

    Http::fake(['seerr.local:5055/api/v1/request/42/retry' => Http::response([], 200)]);

    $this->actingAs($admin)
        ->from(route('media.requests.index'))
        ->post(route('media.requests.retry', 42))
        ->assertRedirect(route('media.requests.index'));

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/api/v1/request/42/retry')
    );
});

test('member cannot retry a request', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->post(route('media.requests.retry', 42))
        ->assertForbidden();
});

test('viewer cannot approve decline or retry requests', function (): void {
    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->post(route('media.requests.approve', 42))
        ->assertForbidden();

    $this->actingAs($viewer)
        ->post(route('media.requests.decline', 42))
        ->assertForbidden();

    $this->actingAs($viewer)
        ->post(route('media.requests.retry', 42))
        ->assertForbidden();
});

test('approve redirects when no active seerr connection', function (): void {
    $this->connection->update(['is_active' => false]);
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->post(route('media.requests.approve', 42))
        ->assertRedirect(route('dashboard'));
});

test('approve handles connection failure gracefully', function (): void {
    $member = User::factory()->member()->create();

    Http::fake(['seerr.local:5055/api/v1/request/42/approve' => Http::response('Server Error', 500)]);

    $this->actingAs($member)
        ->from(route('media.requests.index'))
        ->post(route('media.requests.approve', 42))
        ->assertRedirect(route('media.requests.index'));
});

test('admin can fetch edit options for a TV request from Sonarr', function (): void {
    $admin = User::factory()->admin()->create();
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'sonarr-key',
    ]);

    Http::fake([
        'seerr.local:5055/api/v1/request/42' => Http::response([
            'id' => 42,
            'media' => ['id' => 100, 'mediaType' => 'tv', 'tmdbId' => 1396],
            'profileId' => 7,
            'rootFolder' => '/tv',
            'serverId' => 0,
            'is4k' => false,
        ]),
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([
            ['id' => 7, 'name' => 'HD-1080p'],
            ['id' => 9, 'name' => 'Ultra-HD'],
        ]),
        'sonarr.local:8989/api/v3/rootfolder' => Http::response([
            ['path' => '/tv', 'freeSpace' => 1_000_000_000],
        ]),
    ]);

    $this->actingAs($admin)
        ->getJson(route('media.requests.edit-options', 42))
        ->assertOk()
        ->assertJsonPath('media_type', 'tv')
        ->assertJsonPath('current.profile_id', 7)
        ->assertJsonPath('current.media_id', 100)
        ->assertJsonPath('profiles.1.name', 'Ultra-HD')
        ->assertJsonPath('root_folders.0.path', '/tv');
});

test('admin can update a request and the PUT carries the merged body', function (): void {
    $admin = User::factory()->admin()->create();

    Http::fake([
        'seerr.local:5055/api/v1/request/42' => Http::sequence()
            ->push([
                'id' => 42,
                'media' => ['id' => 100, 'mediaType' => 'movie', 'tmdbId' => 603],
                'profileId' => 7,
                'rootFolder' => '/movies',
                'serverId' => 0,
                'is4k' => false,
                'tags' => [3],
            ])
            ->push(['ok' => true]),
    ]);

    $this->actingAs($admin)
        ->from(route('media.requests.index'))
        ->put(route('media.requests.update', 42), [
            'profile_id' => 9,
            'root_folder' => '/movies-uhd',
        ])
        ->assertRedirect(route('media.requests.index'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');

    Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
        && str_ends_with((string) $request->url(), '/api/v1/request/42')
        && ($request->data()['mediaType'] ?? null) === 'movie'
        && ($request->data()['mediaId'] ?? null) === 100
        && ($request->data()['profileId'] ?? null) === 9
        && ($request->data()['rootFolder'] ?? null) === '/movies-uhd'
        && ($request->data()['serverId'] ?? null) === 0
        && ($request->data()['is4k'] ?? null) === false
        && ($request->data()['tags'] ?? null) === [3]
    );
});

test('member cannot edit a Seerr request', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->put(route('media.requests.update', 42), [
            'profile_id' => 9,
            'root_folder' => '/movies',
        ])
        ->assertForbidden();
});

test('admin can bulk-clear available requests', function (): void {
    $admin = User::factory()->admin()->create();

    Http::fake([
        'seerr.local:5055/api/v1/request*' => Http::sequence()
            ->push([
                'pageInfo' => ['page' => 1, 'pages' => 1, 'pageSize' => 100, 'results' => 2],
                'results' => [
                    ['id' => 11, 'status' => 5, 'media' => ['mediaType' => 'movie', 'tmdbId' => 1]],
                    ['id' => 22, 'status' => 5, 'media' => ['mediaType' => 'movie', 'tmdbId' => 2]],
                ],
            ])
            ->push(['ok' => true])
            ->push(['ok' => true]),
    ]);

    $this->actingAs($admin)
        ->from(route('media.requests.index'))
        ->post(route('media.requests.clear'), ['status' => 'available'])
        ->assertRedirect(route('media.requests.index', ['status' => 'available']))
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_ends_with((string) $request->url(), '/api/v1/request/11')
    );
    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_ends_with((string) $request->url(), '/api/v1/request/22')
    );
});

test('bulk-clear declined uses local status filter', function (): void {
    $admin = User::factory()->admin()->create();

    Http::fake([
        'seerr.local:5055/api/v1/request*' => Http::sequence()
            ->push([
                'pageInfo' => ['page' => 1, 'pages' => 1, 'pageSize' => 100, 'results' => 2],
                'results' => [
                    ['id' => 30, 'status' => 3, 'media' => ['mediaType' => 'movie', 'tmdbId' => 1]],
                    ['id' => 31, 'status' => 1, 'media' => ['mediaType' => 'movie', 'tmdbId' => 2]],
                ],
            ])
            ->push(['ok' => true]),
    ]);

    $this->actingAs($admin)
        ->from(route('media.requests.index'))
        ->post(route('media.requests.clear'), ['status' => 'declined'])
        ->assertRedirect(route('media.requests.index', ['status' => 'declined']));

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_ends_with((string) $request->url(), '/api/v1/request/30')
    );
    Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_ends_with((string) $request->url(), '/api/v1/request/31')
    );
});

test('bulk-clear rejects non-clearable status', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->from(route('media.requests.index'))
        ->post(route('media.requests.clear'), ['status' => 'pending'])
        ->assertRedirect(route('media.requests.index'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');
});

test('member cannot bulk-clear', function (): void {
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->post(route('media.requests.clear'), ['status' => 'completed'])
        ->assertForbidden();
});
