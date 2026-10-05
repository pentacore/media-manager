<?php

declare(strict_types=1);

use App\Models\EmbyActivity;
use App\Models\EmbyUserLink;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    // Deactivated, not missing: an inactive connection must count as none.
    ServiceConnection::factory()->emby()->inactive()->create(['url' => 'http://emby.local:8096']);
});

test('Now Playing without an active Emby goes to the dashboard with the standard toast', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('monitoring.now-playing'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => 'No active Emby connection configured.']);

    Http::assertNothingSent();
});

/**
 * A watched row owned by $user, for the played-state write.
 */
function embyWithoutConnectionActivity(User $user): EmbyActivity
{
    $link = EmbyUserLink::factory()->create(['user_id' => $user->id, 'emby_user_id' => 'emby-user-1', 'emby_username' => 'emby-1']);

    return EmbyActivity::factory()->create(['emby_user_link_id' => $link->id, 'emby_item_id' => 'item-1']);
}

test('an Emby write without an active Emby goes back with the standard toast', function (string $write): void {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create();

    [$url, $data] = match ($write) {
        'refresh the library' => [route('monitoring.now-playing.refresh-library'), []],
        'link my own account' => [route('emby.links.store'), ['emby_username' => 'me', 'password' => 'secret']],
        'link a user from the directory' => [route('emby.links.directory.store'), ['user_id' => $other->id, 'emby_user_id' => 'abc-123']],
        'admin link by username' => [route('admin.users.link-emby', $other), ['emby_username' => 'someone']],
        'admin import' => [route('admin.users.import-from-emby'), []],
        'mark a watched row played' => [route('monitoring.watch-history.played', embyWithoutConnectionActivity($admin)), ['played' => true]],
    };

    $this->actingAs($admin)
        ->from(route('dashboard'))
        ->post($url, $data)
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => 'No active Emby connection configured.']);

    Http::assertNothingSent();
})->with([
    'refresh the library',
    'link my own account',
    'link a user from the directory',
    'admin link by username',
    'admin import',
    'mark a watched row played',
]);

test('the Emby read pages render without an active Emby', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('monitoring.watch-history'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Emby/WatchHistory')->where('connection', null));
    $this->actingAs($admin)
        ->get(route('emby.links.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->loadDeferredProps('emby-users', fn ($reload) => $reload
            ->where('embyUsers', ['users' => [], 'error' => 'No active Emby connection is configured.'])));
    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->loadDeferredProps(fn ($reload) => $reload->where('nowPlaying', [])));

    Http::assertNothingSent();
});
