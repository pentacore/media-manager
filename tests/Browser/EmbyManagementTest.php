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

/**
 * Hands an EmbyPlaybackUpdated broadcast to the page's emby.activity
 * listeners. Tests broadcast through the null driver, so this emits on the
 * pusher-js channel Echo subscribed to; it throws when the page never did.
 *
 * @param  array<string, mixed>  $payload
 */
function embyManagementEmitPlaybackScript(array $payload): string
{
    $json = json_encode($payload, JSON_THROW_ON_ERROR);

    return <<<JS
        (() => {
            const channel = window.Pusher?.instances?.[0]?.channel('private-emby.activity');
            if (!channel) {
                throw new Error('The page has not subscribed to emby.activity.');
            }
            channel.emit('EmbyPlaybackUpdated', {$json});
        })()
    JS;
}

/**
 * An EmbyPlaybackUpdated payload as the event broadcasts it.
 *
 * @return array<string, mixed>
 */
function embyManagementPlaybackPayload(int $id, ?int $ownerId, string $title): array
{
    return [
        'id' => $id,
        'emby_user_link_id' => 1,
        'user_id' => $ownerId,
        'media_type' => 'Episode',
        'media_title' => $title,
        'series_title' => 'Example Series',
        'action' => 'stopped',
        'play_position' => 1_200_000_000,
        'duration_ticks' => 18_000_000_000,
        'emby_username' => 'Example User',
        'created_at' => '2026-10-12T10:00:00.000000Z',
        'updated_at' => '2026-10-12T10:00:00.000000Z',
    ];
}

test('rows pushed live offer the played toggle to their owner only, and an update keeps it', function (): void {
    fakeEmbyManagement();
    $member = User::factory()->member()->create();
    $other = User::factory()->member()->create();
    $ownLink = EmbyUserLink::factory()->create(['user_id' => $member->id, 'emby_user_id' => 'emby-2', 'emby_username' => 'Bob']);
    $own = EmbyActivity::factory()->create(['emby_user_link_id' => $ownLink->id, 'emby_item_id' => 'item-own', 'media_title' => 'Severance']);
    $this->actingAs($member);

    $webpage = visit(route('monitoring.watch-history', absolute: false))
        ->assertNoSmoke()
        ->assertVisible("[data-mark-played=\"{$own->id}\"]");

    $webpage->script(embyManagementEmitPlaybackScript(embyManagementPlaybackPayload(990_001, $member->id, 'Example Show One')));
    $webpage->script(embyManagementEmitPlaybackScript(embyManagementPlaybackPayload(990_002, $other->id, 'Example Show Two')));
    $webpage->script(embyManagementEmitPlaybackScript(embyManagementPlaybackPayload($own->id, $member->id, 'Severance (resumed)')));
    $webpage->script(confirmDialogWaitUntilScript("document.querySelector('[data-watch-row=\"990002\"]') !== null"));

    $webpage->assertSeeIn('[data-watch-row="990001"]', 'Example Show One')
        ->assertSeeIn('[data-watch-row="990002"]', 'Example Show Two')
        ->assertSeeIn("[data-watch-row=\"{$own->id}\"]", 'Severance (resumed)')
        ->assertVisible('[data-mark-played="990001"]')
        ->assertMissing('[data-mark-played="990002"]')
        ->assertVisible("[data-mark-played=\"{$own->id}\"]")
        ->assertNoSmoke();
});

test("an admin gets the played toggle on anyone's live row", function (): void {
    fakeEmbyManagement();
    $owner = User::factory()->member()->create();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('monitoring.watch-history', absolute: false))->assertNoSmoke();

    $webpage->script(embyManagementEmitPlaybackScript(embyManagementPlaybackPayload(990_003, $owner->id, 'Example Show Three')));
    $webpage->script(confirmDialogWaitUntilScript("document.querySelector('[data-watch-row=\"990003\"]') !== null"));

    $webpage->assertSeeIn('[data-watch-row="990003"]', 'Example Show Three')
        ->assertVisible('[data-mark-played="990003"]')
        ->assertNoSmoke();
});
