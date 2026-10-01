<?php

declare(strict_types=1);

use App\Models\EmbyUserLink;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Seerr\SeerrUserResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    $this->emby = ServiceConnection::factory()->emby()->create(['url' => 'http://emby.local:8096', 'api_key' => 'emby-api-key']);
    $this->admin = User::factory()->admin()->create();
});

function embyDirectoryUsersFake(): void
{
    Http::fake(['emby.local:8096/Users' => Http::response([
        [
            'Id' => 'emby-2', 'Name' => 'bob', 'LastActivityDate' => '2026-09-28T20:15:00.0000000Z',
            'Policy' => ['IsAdministrator' => false, 'AuthenticationProviderId' => 'secret-provider'],
            'Configuration' => ['AudioLanguagePreference' => 'eng'], 'ConnectUserName' => 'bob@example.com',
        ],
        ['Id' => 'emby-1', 'Name' => 'Alice', 'LastActivityDate' => null, 'Policy' => ['IsAdministrator' => true]],
    ])]);
}

test('admins see every Emby user with the app user it is linked to', function (): void {
    embyDirectoryUsersFake();
    $alice = User::factory()->create(['name' => 'Alice Appuser']);
    EmbyUserLink::factory()->create(['user_id' => $alice->id, 'emby_user_id' => 'emby-1', 'emby_username' => 'Alice']);

    $this->actingAs($this->admin)
        ->get(route('emby.links.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Emby/UserLinks')
            ->has('appUsers', 2)
            ->loadDeferredProps('emby-users', fn ($page) => $page
                ->where('embyUsers.error', null)
                ->where('embyUsers.users.0', [
                    'id' => 'emby-1', 'name' => 'Alice', 'is_admin' => true, 'last_activity_at' => null,
                    'link' => ['id' => EmbyUserLink::query()->sole()->id, 'user' => ['id' => $alice->id, 'name' => 'Alice Appuser']],
                ])
                ->where('embyUsers.users.1.name', 'bob')
                ->where('embyUsers.users.1.is_admin', false)
                ->where('embyUsers.users.1.last_activity_at', '2026-09-28T20:15:00+00:00')
                ->where('embyUsers.users.1.link', null)));
});

test('the Emby user list is cached for a minute per connection', function (): void {
    embyDirectoryUsersFake();

    foreach (range(1, 2) as $visit) {
        $this->actingAs($this->admin)
            ->get(route('emby.links.index'))
            ->assertInertia(fn ($page) => $page->loadDeferredProps('emby-users', fn ($page) => $page->has('embyUsers.users', 2)));
    }

    Http::assertSentCount(1);

    $this->travel(61)->seconds();

    $this->actingAs($this->admin)
        ->get(route('emby.links.index'))
        ->assertInertia(fn ($page) => $page->loadDeferredProps('emby-users', fn ($page) => $page->has('embyUsers.users', 2)));

    Http::assertSentCount(2);
});

test('an Emby outage is an error, never an empty list, and is not cached', function (): void {
    Sleep::fake();
    Http::fake(['emby.local:8096/Users' => Http::sequence()->push('boom', 500)->push('boom', 500)->push('boom', 500)->push([['Id' => 'emby-1', 'Name' => 'Alice']])]);

    $this->actingAs($this->admin)
        ->get(route('emby.links.index'))
        ->assertInertia(fn ($page) => $page->loadDeferredProps('emby-users', fn ($page) => $page
            ->where('embyUsers.users', [])
            ->where('embyUsers.error', 'Emby is unreachable right now — the user list could not be loaded.')));

    $this->actingAs($this->admin)
        ->get(route('emby.links.index'))
        ->assertInertia(fn ($page) => $page->loadDeferredProps('emby-users', fn ($page) => $page
            ->where('embyUsers.error', null)
            ->has('embyUsers.users', 1)));
});

test('an Emby answer that is not a user list is an error and is not cached', function (mixed $body): void {
    Http::fake(['emby.local:8096/Users' => Http::sequence()->push($body)->push([['Id' => 'emby-1', 'Name' => 'Alice']])]);

    $this->actingAs($this->admin)
        ->get(route('emby.links.index'))
        ->assertInertia(fn ($page) => $page->loadDeferredProps('emby-users', fn ($page) => $page
            ->where('embyUsers.users', [])
            ->where('embyUsers.error', 'Emby is unreachable right now — the user list could not be loaded.')));

    $this->actingAs($this->admin)
        ->get(route('emby.links.index'))
        ->assertInertia(fn ($page) => $page->loadDeferredProps('emby-users', fn ($page) => $page
            ->where('embyUsers.error', null)
            ->has('embyUsers.users', 1)));
})->with([
    'an SSO login page' => ['<html><body>Sign in to continue</body></html>'],
    'a JSON object' => [['Items' => [], 'TotalRecordCount' => 0]],
]);

test('a malformed last activity date shows as never instead of failing the list', function (): void {
    Http::fake(['emby.local:8096/Users' => Http::response([
        ['Id' => 'emby-1', 'Name' => 'Alice', 'LastActivityDate' => 'not-a-date'],
        ['Id' => 'emby-2', 'Name' => 'bob', 'LastActivityDate' => '2026-09-28T20:15:00.0000000Z'],
    ])]);

    $this->actingAs($this->admin)
        ->get(route('emby.links.index'))
        ->assertInertia(fn ($page) => $page->loadDeferredProps('emby-users', fn ($page) => $page
            ->where('embyUsers.error', null)
            ->where('embyUsers.users.0.last_activity_at', null)
            ->where('embyUsers.users.1.last_activity_at', '2026-09-28T20:15:00+00:00')));
});

test('without an active Emby connection the list says so', function (): void {
    $this->emby->update(['is_active' => false]);

    $this->actingAs($this->admin)
        ->get(route('emby.links.index'))
        ->assertInertia(fn ($page) => $page->loadDeferredProps('emby-users', fn ($page) => $page
            ->where('embyUsers.error', 'No active Emby connection is configured.')));

    Http::assertNothingSent();
});

test('an admin links an app user to an Emby user picked from the list', function (): void {
    embyDirectoryUsersFake();
    $bobby = User::factory()->create(['name' => 'Bobby']);

    $this->actingAs($this->admin)
        ->from(route('emby.links.index'))
        ->post(route('emby.links.directory.store'), ['user_id' => $bobby->id, 'emby_user_id' => 'emby-2'])
        ->assertRedirect(route('emby.links.index'))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Linked bob to Bobby.');

    expect(EmbyUserLink::query()->sole()->only(['user_id', 'emby_user_id', 'emby_username']))
        ->toBe(['user_id' => $bobby->id, 'emby_user_id' => 'emby-2', 'emby_username' => 'bob']);
});

test('linking refuses an Emby user that is gone or already linked', function (): void {
    embyDirectoryUsersFake();
    $bobby = User::factory()->create();
    EmbyUserLink::factory()->create(['emby_user_id' => 'emby-1']);

    $this->actingAs($this->admin)
        ->from(route('emby.links.index'))
        ->post(route('emby.links.directory.store'), ['user_id' => $bobby->id, 'emby_user_id' => 'emby-404'])
        ->assertSessionHas('inertia.flash_data.toast.message', 'That Emby user no longer exists — refresh the list.');

    $this->actingAs($this->admin)
        ->from(route('emby.links.index'))
        ->post(route('emby.links.directory.store'), ['user_id' => $bobby->id, 'emby_user_id' => 'emby-1'])
        ->assertSessionHas('inertia.flash_data.toast.message', 'That Emby account is already linked to another user.');

    expect(EmbyUserLink::query()->count())->toBe(1);
});

test('linking from the directory is admin-only and validates its input', function (): void {
    $this->actingAs(User::factory()->member()->create())
        ->post(route('emby.links.directory.store'), ['user_id' => $this->admin->id, 'emby_user_id' => 'emby-1'])
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->post(route('emby.links.directory.store'), ['user_id' => 999_999, 'emby_user_id' => '../Users'])
        ->assertSessionHasErrors(['user_id', 'emby_user_id']);

    Http::assertNothingSent();
});

test('the directory payload carries only the listed fields', function (): void {
    embyDirectoryUsersFake();

    $this->actingAs($this->admin)
        ->get(route('emby.links.index'))
        ->assertInertia(fn ($page) => $page->loadDeferredProps('emby-users', function ($page): void {
            expect(json_encode($page->toArray(), JSON_THROW_ON_ERROR))
                ->not->toContain('secret-provider')
                ->not->toContain('bob@example.com')
                ->not->toContain('emby-api-key');
        }));

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Emby-Token', 'emby-api-key'));
});

test('linking from the directory clears the cached Seerr match for that user, like linking through the login flow does', function (): void {
    embyDirectoryUsersFake();
    $bobby = User::factory()->create(['name' => 'Bobby', 'email' => 'nobody@example.com']);
    $seerr = ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055', 'api_key' => 'k']);
    Http::fake(['seerr.local:5055/api/v1/user*' => Http::response([
        'pageInfo' => ['pages' => 1, 'page' => 1, 'results' => 1],
        'results' => [['id' => 4, 'email' => 'somebody-else@example.com', 'jellyfinUserId' => 'emby2']],
    ])]);
    $seerrUserResolver = resolve(SeerrUserResolver::class);

    expect($seerrUserResolver->resolve($seerr, $bobby))->toBeNull();

    $this->actingAs($this->admin)
        ->post(route('emby.links.directory.store'), ['user_id' => $bobby->id, 'emby_user_id' => 'emby-2']);

    expect($seerrUserResolver->resolve($seerr, $bobby))->toBe(4);
});
