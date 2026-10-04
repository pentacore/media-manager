<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    // Deactivated, not missing: an inactive connection must count as none.
    ServiceConnection::factory()->seerr()->inactive()->create(['url' => 'http://seerr.local:5055']);
});

test('a Seerr page or write without an active Seerr connection goes to the dashboard with the standard toast', function (string $method, string $routeName, array $parameters, array $data): void {
    $this->actingAs(User::factory()->admin()->create())
        ->{$method}(route($routeName, $parameters), $data)
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => 'No active Seerr connection configured.']);

    Http::assertNothingSent();
})->with([
    'requests index' => ['get', 'media.requests.index', [], []],
    'delete a request' => ['delete', 'media.requests.destroy', [42], []],
    'approve' => ['post', 'media.requests.approve', [42], []],
    'decline' => ['post', 'media.requests.decline', [42], []],
    'retry' => ['post', 'media.requests.retry', [42], []],
    'edit a request' => ['put', 'media.requests.update', [42], ['profile_id' => 1, 'root_folder' => '/tv']],
    'bulk clear' => ['post', 'media.requests.clear', [], ['status' => 'available']],
    'anime season' => ['get', 'media.anime.index', [], []],
    'anime request' => ['post', 'media.anime.request', [], ['tmdbId' => 1, 'mediaType' => 'tv']],
    'anime find match' => ['post', 'media.anime.find-match', [], ['title' => 'Frieren']],
]);

test('cancelling my own request without an active Seerr connection goes back with the standard toast', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->from(route('media.requests.mine'))
        ->delete(route('media.requests.mine.destroy', 42))
        ->assertRedirect(route('media.requests.mine'))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => 'No active Seerr connection configured.']);

    Http::assertNothingSent();
});

test('a Discover request without an active Seerr connection goes back with a failed outcome', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->from(route('media.discover.index'))
        ->post(route('media.discover.request'), ['tmdbId' => 1, 'mediaType' => 'movie'])
        ->assertRedirect(route('media.discover.index'))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => 'No active Seerr connection configured.'])
        ->assertSessionHas('inertia.flash_data.requestOutcome', ['ok' => false, 'tmdbId' => 1, 'mediaType' => 'movie']);

    Http::assertNothingSent();
});

test('the Seerr JSON endpoints answer 422 without an active Seerr connection', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->getJson(route('media.requests.edit-options', 42))
        ->assertUnprocessable()
        ->assertJsonPath('error', 'no_seerr_connection');
    $this->actingAs($admin)
        ->getJson(route('media.discover.title', ['mediaType' => 'movie', 'tmdbId' => 1]))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'No active Seerr connection configured.');

    Http::assertNothingSent();
});

test('the Discover and My requests pages render as not connected', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('media.discover.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Discover/Index')->where('seerr', ['connected' => false])->missing('trending'));
    $this->actingAs($admin)
        ->get(route('media.requests.mine'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Seerr/MyRequests')->where('seerr', ['connected' => false])->where('filters', ['page' => 1])->missing('requests'));

    Http::assertNothingSent();
});
