<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    $this->connection = ServiceConnection::factory()->jellyseerr()->create([
        'url' => 'http://jellyseerr.local:5055',
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

test('members can list requests', function (): void {
    $member = User::factory()->member()->create();

    Http::fake([
        'jellyseerr.local:5055/api/v1/request*' => Http::response([
            'results' => [
                [
                    'id' => 1,
                    'status' => 1,
                    'type' => 'movie',
                    'media' => ['mediaType' => 'movie', 'title' => 'My Movie', 'tmdbId' => 555],
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
            ->component('Jellyseerr/Requests')
            ->has('requests', 1)
            ->where('requests.0.media_title', 'My Movie')
            ->where('requests.0.requester', 'Alice')
        );
});

test('requests index redirects when no active jellyseerr connection', function (): void {
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

    Http::fake(['jellyseerr.local:5055/api/v1/request/42' => Http::response(null, 200)]);

    $this->actingAs($admin)
        ->delete(route('media.requests.destroy', 42))
        ->assertRedirect(route('media.requests.index'));

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_ends_with((string) $request->url(), '/api/v1/request/42')
    );
});

test('admin delete handles connection failure gracefully', function (): void {
    $admin = User::factory()->admin()->create();

    Http::fake(['jellyseerr.local:5055/api/v1/request/42' => Http::response('Server Error', 500)]);

    $this->actingAs($admin)
        ->from(route('media.requests.index'))
        ->delete(route('media.requests.destroy', 42))
        ->assertRedirect(route('media.requests.index'));
});
