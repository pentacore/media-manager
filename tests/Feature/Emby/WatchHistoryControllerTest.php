<?php

declare(strict_types=1);

use App\Models\EmbyActivity;
use App\Models\EmbyUserLink;
use App\Models\User;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
});

test('guests are redirected to login from watch history', function (): void {
    $this->get(route('monitoring.watch-history'))->assertRedirect(route('login'));
});

test('viewer sees only their own activity', function (): void {
    $viewer = User::factory()->create();
    $other = User::factory()->create();

    $myLink = EmbyUserLink::factory()->create(['user_id' => $viewer->id]);
    $otherLink = EmbyUserLink::factory()->create(['user_id' => $other->id]);

    EmbyActivity::factory()->count(3)->create(['emby_user_link_id' => $myLink->id]);
    EmbyActivity::factory()->count(5)->create(['emby_user_link_id' => $otherLink->id]);

    $this->actingAs($viewer)
        ->get(route('monitoring.watch-history'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Emby/WatchHistory')
            ->has('activities.data', 3)
        );
});

test('member sees all activity', function (): void {
    $member = User::factory()->member()->create();

    $link1 = EmbyUserLink::factory()->create();
    $link2 = EmbyUserLink::factory()->create();

    EmbyActivity::factory()->count(2)->create(['emby_user_link_id' => $link1->id]);
    EmbyActivity::factory()->count(3)->create(['emby_user_link_id' => $link2->id]);

    $this->actingAs($member)
        ->get(route('monitoring.watch-history'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('activities.data', 5)
        );
});

test('admin sees all activity', function (): void {
    $admin = User::factory()->admin()->create();

    $link = EmbyUserLink::factory()->create();
    EmbyActivity::factory()->count(4)->create(['emby_user_link_id' => $link->id]);

    $this->actingAs($admin)
        ->get(route('monitoring.watch-history'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('activities.data', 4)
        );
});

test('media_type filter restricts results', function (): void {
    $admin = User::factory()->admin()->create();
    $link = EmbyUserLink::factory()->create();

    EmbyActivity::factory()->count(2)->create(['emby_user_link_id' => $link->id, 'media_type' => 'movie']);
    EmbyActivity::factory()->count(3)->create(['emby_user_link_id' => $link->id, 'media_type' => 'episode']);

    $this->actingAs($admin)
        ->get(route('monitoring.watch-history', ['media_type' => 'movie']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('activities.data', 2)
            ->where('filters.media_type', 'movie')
        );
});

test('viewer sees zero activities when they have no emby link', function (): void {
    $viewer = User::factory()->create();
    $otherLink = EmbyUserLink::factory()->create();
    EmbyActivity::factory()->count(3)->create(['emby_user_link_id' => $otherLink->id]);

    $this->actingAs($viewer)
        ->get(route('monitoring.watch-history'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('activities.data', 0)
        );
});

test('results are paginated at 25 per page', function (): void {
    $admin = User::factory()->admin()->create();
    $link = EmbyUserLink::factory()->create();
    EmbyActivity::factory()->count(30)->create(['emby_user_link_id' => $link->id]);

    $this->actingAs($admin)
        ->get(route('monitoring.watch-history'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('activities.data', 25)
            ->where('activities.meta.total', 30)
            ->where('activities.meta.last_page', 2)
        );
});
