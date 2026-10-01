<?php

declare(strict_types=1);

use App\Models\ActionRequest;
use App\Models\EmbyActivity;
use App\Models\EmbyUserLink;
use App\Models\ServiceConnection;
use App\Models\User;
use Database\Seeders\ActionTypeConfigSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Every Emby endpoint these pages (and the auto-executing scan) touch.
 */
function fakeEmbyManagement(bool $usersDown = false): void
{
    Http::fake([
        'emby.local:8096/Sessions' => Http::response([]),
        'emby.local:8096/Library/Refresh' => Http::response('', 204),
        'emby.local:8096/Users/*/PlayedItems/*' => Http::response(['Played' => true]),
        'emby.local:8096/Users' => $usersDown
            ? Http::response('Unauthorized', 401)
            : Http::response([
                ['Id' => 'emby-1', 'Name' => 'Alice', 'Policy' => ['IsAdministrator' => true], 'LastActivityDate' => '2026-09-28T20:15:00Z'],
                ['Id' => 'emby-2', 'Name' => 'Bob', 'Policy' => ['IsAdministrator' => false], 'LastActivityDate' => null],
            ]),
    ]);
}

beforeEach(function (): void {
    ServiceConnection::factory()->emby()->create(['url' => 'http://emby.local:8096', 'api_key' => 'k']);
});

test('an admin refreshes the Emby library from Now Playing', function (): void {
    fakeEmbyManagement();
    $this->seed(ActionTypeConfigSeeder::class);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('monitoring.now-playing', absolute: false))
        ->assertNoSmoke()
        ->click('[data-emby-refresh-library]')
        ->assertSee('Library refresh queued.')
        ->assertNoSmoke();

    expect(ActionRequest::query()->where('type', 'emby_library_scan')->sole()->origin)->toBe('manual');
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/Library/Refresh'));
});

test('members see no refresh button', function (): void {
    fakeEmbyManagement();
    $this->actingAs(User::factory()->member()->create());

    visit(route('monitoring.now-playing', absolute: false))
        ->assertNoSmoke()
        ->assertMissing('[data-emby-refresh-library]');
});

test("a member marks their own watch history row played but not someone else's", function (): void {
    fakeEmbyManagement();
    $member = User::factory()->member()->create();
    $ownLink = EmbyUserLink::factory()->create(['user_id' => $member->id, 'emby_user_id' => 'emby-2', 'emby_username' => 'Bob']);
    $otherLink = EmbyUserLink::factory()->create(['emby_user_id' => 'emby-1', 'emby_username' => 'Alice']);
    $own = EmbyActivity::factory()->create(['emby_user_link_id' => $ownLink->id, 'emby_item_id' => 'item-own', 'media_title' => 'Severance']);
    $other = EmbyActivity::factory()->create(['emby_user_link_id' => $otherLink->id, 'emby_item_id' => 'item-other', 'media_title' => 'Andor']);
    $this->actingAs($member);

    visit(route('monitoring.watch-history', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn("[data-watch-row=\"{$other->id}\"]", 'Andor')
        ->assertMissing("[data-mark-played=\"{$other->id}\"]")
        ->click("[data-mark-played=\"{$own->id}\"]")
        ->assertSee('Marked as played.')
        ->assertNoSmoke();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/Users/emby-2/PlayedItems/item-own'));
});

test('an admin links an app user to an Emby user from the list', function (): void {
    fakeEmbyManagement();
    $this->actingAs(User::factory()->admin()->create(['name' => 'Ada']));
    $bobby = User::factory()->create(['name' => 'Bobby']);

    visit(route('emby.links.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-emby-user="emby-2"]', 'Bob')
        ->click('[data-emby-link-trigger="emby-2"]')
        ->click("[data-emby-link-option=\"{$bobby->id}\"]")
        ->click('[data-emby-link-submit="emby-2"]')
        ->assertSee('Linked Bob to Bobby.')
        ->assertSeeIn('[data-emby-user="emby-2"]', 'Bobby')
        ->assertNoSmoke();
});

test('an Emby outage shows an error banner instead of an empty list', function (): void {
    fakeEmbyManagement(usersDown: true);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('emby.links.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-emby-users-error]', 'Emby is unreachable right now')
        ->assertMissing('[data-emby-user]');
});
