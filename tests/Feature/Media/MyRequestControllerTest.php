<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Seerr\SeerrClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
});

/**
 * @param  array<string, mixed>  $extra
 */
function fakeMyRequestsSeerr(array $extra = []): void
{
    Http::fake(array_merge([
        'seerr.local:5055/api/v1/user*' => Http::response(['pageInfo' => ['pages' => 1, 'page' => 1], 'results' => [['id' => 7, 'email' => 'viewer@example.com']]]),
        'seerr.local:5055/api/v1/request/41' => fn (Request $request) => $request->method() === 'DELETE'
            ? Http::response(null, 204)
            : Http::response(['id' => 41, 'status' => 1, 'requestedBy' => ['id' => 7], 'media' => ['tmdbId' => 1, 'mediaType' => 'movie']]),
        'seerr.local:5055/api/v1/request*' => Http::response(['pageInfo' => ['pages' => 1, 'page' => 1, 'results' => 2, 'pageSize' => 20], 'results' => [
            ['id' => 41, 'status' => 1, 'type' => 'movie', 'createdAt' => '2026-09-20T10:00:00.000Z', 'media' => ['tmdbId' => 1, 'mediaType' => 'movie', 'status' => 2], 'requestedBy' => ['id' => 7]],
            ['id' => 42, 'status' => 2, 'type' => 'tv', 'createdAt' => '2026-09-19T10:00:00.000Z', 'media' => ['tmdbId' => 2, 'mediaType' => 'tv', 'status' => 5], 'requestedBy' => ['id' => 7]],
        ]]),
        'seerr.local:5055/api/v1/movie/1' => Http::response(['id' => 1, 'title' => 'Dune', 'posterPath' => '/d.jpg']),
        'seerr.local:5055/api/v1/tv/2' => Http::response(['id' => 2, 'name' => 'Severance', 'posterPath' => '/s.jpg']),
    ], $extra));
}

test("my requests lists the resolved user's own requests with cancel only on pending ones", function (): void {
    fakeMyRequestsSeerr();

    $this->actingAs(User::factory()->create(['email' => 'viewer@example.com']))
        ->get(route('media.requests.mine'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Seerr/MyRequests')
            ->where('seerr.connected', true)
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('requests.linked', true)
                ->where('requests.error', null)
                ->where('requests.results.0.id', 41)
                ->where('requests.results.0.title', 'Dune')
                ->where('requests.results.0.status', 'pending')
                ->where('requests.results.0.can_cancel', true)
                ->where('requests.results.1.status', 'available')
                ->where('requests.results.1.can_cancel', false)
                ->where('requests.meta.total', 2)));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/request') && ($request['requestedBy'] ?? null) === 7);
});

test('a user with no Seerr account sees the unlinked state', function (): void {
    fakeMyRequestsSeerr();

    $this->actingAs(User::factory()->create(['email' => 'nobody@example.com']))
        ->get(route('media.requests.mine'))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('requests.linked', false)
                ->where('requests.results', [])));
});

test('a row whose requestedBy is not the caller is dropped, even though Seerr already scoped the query', function (): void {
    fakeMyRequestsSeerr(['seerr.local:5055/api/v1/request*' => Http::response(['pageInfo' => ['pages' => 1, 'page' => 1, 'results' => 2, 'pageSize' => 20], 'results' => [
        ['id' => 41, 'status' => 1, 'type' => 'movie', 'createdAt' => '2026-09-20T10:00:00.000Z', 'media' => ['tmdbId' => 1, 'mediaType' => 'movie', 'status' => 2], 'requestedBy' => ['id' => 7]],
        ['id' => 99, 'status' => 1, 'type' => 'movie', 'createdAt' => '2026-09-19T10:00:00.000Z', 'media' => ['tmdbId' => 3, 'mediaType' => 'movie', 'status' => 2], 'requestedBy' => ['id' => 55]],
    ]])]);

    $this->actingAs(User::factory()->create(['email' => 'viewer@example.com']))
        ->get(route('media.requests.mine'))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('requests.results.0.id', 41)
                ->has('requests.results', 1)));
});

test('an unreachable Seerr is an error state, not an empty list', function (): void {
    Http::fake(['seerr.local:5055/*' => Http::response([], 503)]);

    $this->actingAs(User::factory()->create())
        ->get(route('media.requests.mine'))
        ->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reload) => $reload->where('requests.error', 'Seerr is unreachable right now.')));
});

test('a viewer cancels their own pending request', function (): void {
    fakeMyRequestsSeerr();

    $this->actingAs(User::factory()->create(['email' => 'viewer@example.com']))
        ->delete(route('media.requests.mine.destroy', ['id' => 41]))
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast.message', 'Request cancelled.');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/api/v1/request/41'));
});

test("cancelling someone else's request is forbidden and never reaches Seerr's delete", function (): void {
    fakeMyRequestsSeerr(['seerr.local:5055/api/v1/request/41' => Http::response(['id' => 41, 'status' => 1, 'requestedBy' => ['id' => 99]])]);

    $this->actingAs(User::factory()->create(['email' => 'viewer@example.com']))
        ->delete(route('media.requests.mine.destroy', ['id' => 41]))
        ->assertForbidden();

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

test('an approved request cannot be cancelled', function (): void {
    fakeMyRequestsSeerr(['seerr.local:5055/api/v1/request/41' => Http::response(['id' => 41, 'status' => 2, 'requestedBy' => ['id' => 7]])]);

    $this->actingAs(User::factory()->create(['email' => 'viewer@example.com']))
        ->delete(route('media.requests.mine.destroy', ['id' => 41]))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Only pending requests can be cancelled.');

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

test('a user with no Seerr account is forbidden before any request is ever read from Seerr', function (): void {
    fakeMyRequestsSeerr();

    $this->actingAs(User::factory()->create(['email' => 'nobody@example.com']))
        ->delete(route('media.requests.mine.destroy', ['id' => 41]))
        ->assertForbidden();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/request/41'));
});

test('a failure walking the Seerr user list is reported as unreachable, not "request no longer exists"', function (): void {
    fakeMyRequestsSeerr(['seerr.local:5055/api/v1/user*' => Http::response([], 404)]);

    $this->actingAs(User::factory()->create(['email' => 'viewer@example.com']))
        ->delete(route('media.requests.mine.destroy', ['id' => 41]))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Seerr is unreachable right now.');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/request/41'));
});

test('the ownership check reads the live request, not a cached copy', function (): void {
    // Http::fake keeps the first matching stub, so the change of state is
    // modelled as a sequence: the warm-up read sees "pending", the live
    // ownership read sees "approved".
    fakeMyRequestsSeerr(['seerr.local:5055/api/v1/request/41' => Http::sequence()
        ->push(['id' => 41, 'status' => 1, 'requestedBy' => ['id' => 7]])
        ->push(['id' => 41, 'status' => 2, 'requestedBy' => ['id' => 7]])]);
    $viewer = User::factory()->create(['email' => 'viewer@example.com']);

    // Warm the entity cache with the pending copy a cached read would reuse.
    new SeerrClient(ServiceConnection::query()->firstOrFail())->getRequestById(41);

    $this->actingAs($viewer)
        ->delete(route('media.requests.mine.destroy', ['id' => 41]))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Only pending requests can be cancelled.');

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});
