<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\EmbyActivity;
use App\Models\EmbyUserLink;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    ServiceConnection::factory()->emby()->create(['url' => 'http://emby.local:8096', 'api_key' => 'k']);
});

/**
 * @return array{0: User, 1: EmbyActivity}
 */
function playedStateRow(?User $owner = null): array
{
    $owner ??= User::factory()->create();
    $link = EmbyUserLink::factory()->create(['user_id' => $owner->id, 'emby_user_id' => 'emby-user-'.$owner->id, 'emby_username' => 'emby-'.$owner->id]);

    return [$owner, EmbyActivity::factory()->create(['emby_user_link_id' => $link->id, 'emby_item_id' => 'item-'.$owner->id, 'media_title' => 'Severance'])];
}

test('a viewer marks their own row played and unplayed', function (): void {
    [$viewer, $activity] = playedStateRow();
    Http::fake(['emby.local:8096/Users/*/PlayedItems/*' => Http::response(['Played' => true])]);

    $this->actingAs($viewer)
        ->from(route('monitoring.watch-history'))
        ->post(route('monitoring.watch-history.played', $activity), ['played' => true])
        ->assertRedirect(route('monitoring.watch-history'))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Marked as played.');

    $this->actingAs($viewer)
        ->from(route('monitoring.watch-history'))
        ->post(route('monitoring.watch-history.played', $activity), ['played' => false])
        ->assertSessionHas('inertia.flash_data.toast.message', 'Marked as unplayed.');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), sprintf('/Users/emby-user-%d/PlayedItems/item-%d', $viewer->id, $viewer->id)));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_ends_with($request->url(), sprintf('/Users/emby-user-%d/PlayedItems/item-%d', $viewer->id, $viewer->id)));

    expect(ActivityLog::query()->where('action', 'emby.item.marked_played')->sole()->user_id)->toBe($viewer->id)
        ->and(ActivityLog::query()->where('action', 'emby.item.marked_unplayed')->exists())->toBeTrue();
});

test("a member cannot change another user's played state by row id", function (): void {
    [, $othersActivity] = playedStateRow();

    foreach ([User::factory()->member()->create(), User::factory()->create()] as $intruder) {
        $this->actingAs($intruder)
            ->post(route('monitoring.watch-history.played', $othersActivity), ['played' => true])
            ->assertForbidden();
    }

    Http::assertNothingSent();
});

test('an admin can change any row', function (): void {
    [, $activity] = playedStateRow();
    Http::fake(['emby.local:8096/Users/*/PlayedItems/*' => Http::response(['Played' => true])]);

    $this->actingAs(User::factory()->admin()->create())
        ->from(route('monitoring.watch-history'))
        ->post(route('monitoring.watch-history.played', $activity), ['played' => true])
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');
});

test('Emby refusals and outages get their own toasts', function (int $status, string $message): void {
    Sleep::fake();
    [$viewer, $activity] = playedStateRow();
    Http::fake(['emby.local:8096/Users/*/PlayedItems/*' => Http::response('nope', $status)]);

    $this->actingAs($viewer)
        ->from(route('monitoring.watch-history'))
        ->post(route('monitoring.watch-history.played', $activity), ['played' => true])
        ->assertSessionHas('inertia.flash_data.toast.type', 'error')
        ->assertSessionHas('inertia.flash_data.toast.message', $message);

    expect(ActivityLog::query()->where('action', 'emby.item.marked_played')->exists())->toBeFalse();
})->with([
    'refused' => [404, 'Emby refused the change.'],
    'down' => [503, 'Emby is unreachable right now.'],
]);

test('the played flag is required', function (): void {
    [$viewer, $activity] = playedStateRow();

    $this->actingAs($viewer)
        ->post(route('monitoring.watch-history.played', $activity), [])
        ->assertSessionHasErrors('played');
});

test('watch history rows say which ones the viewer may toggle', function (): void {
    $member = User::factory()->member()->create();
    [, $own] = playedStateRow($member);
    [, $others] = playedStateRow();

    $this->actingAs($member)
        ->get(route('monitoring.watch-history'))
        ->assertInertia(fn ($page) => $page
            ->has('activities.data', 2)
            ->where('activities.data', fn ($rows): bool => collect($rows)->pluck('can_toggle_played', 'id')->all() === [$others->id => false, $own->id => true]
                || collect($rows)->pluck('can_toggle_played', 'id')->all() === [$own->id => true, $others->id => false]));

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('monitoring.watch-history'))
        ->assertInertia(fn ($page) => $page->where('activities.data.0.can_toggle_played', true)->where('activities.data.1.can_toggle_played', true));
});
