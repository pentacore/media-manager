<?php

declare(strict_types=1);

use App\Models\EmbyActivity;
use App\Models\EmbyUserLink;
use App\Models\User;
use Carbon\CarbonImmutable;

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

test('since=today only returns activities from the local current day', function (): void {
    $admin = User::factory()->admin()->create();
    $link = EmbyUserLink::factory()->create();

    CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 4, 29, 14, 30));

    EmbyActivity::factory()->create([
        'emby_user_link_id' => $link->id,
        'created_at' => CarbonImmutable::create(2026, 4, 29, 1, 0),
    ]);
    EmbyActivity::factory()->create([
        'emby_user_link_id' => $link->id,
        'created_at' => CarbonImmutable::create(2026, 4, 28, 23, 30),
    ]);

    $this->actingAs($admin)
        ->get(route('monitoring.watch-history', ['since' => 'today']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.since', 'today')
            ->has('activities.data', 1)
            ->where('filterOptions.todayValue', 'today')
        );

    CarbonImmutable::setTestNow();
});

test('unknown since value falls back to default 7d', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('monitoring.watch-history', ['since' => 'forever']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.since', 7));
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

test('totals reflect full filtered range, not just paginated page', function (): void {
    $admin = User::factory()->admin()->create();

    $heavyLink = EmbyUserLink::factory()->create(['emby_username' => 'heavy']);
    $lightLink = EmbyUserLink::factory()->create(['emby_username' => 'light']);

    // 30 sessions for "heavy" — bigger total than 5 sessions for "light".
    EmbyActivity::factory()->count(30)->create([
        'emby_user_link_id' => $heavyLink->id,
        'duration_ticks' => 100_000_000,
        'play_position' => 90_000_000, // ≥90% — counts as completed
    ]);

    EmbyActivity::factory()->count(5)->create([
        'emby_user_link_id' => $lightLink->id,
        'duration_ticks' => 100_000_000,
        'play_position' => 50_000_000, // <90%
    ]);

    $this->actingAs($admin)
        ->get(route('monitoring.watch-history'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('activities.data', 25) // paginated
            ->where('totals.sessions', 35) // full range
            ->where('totals.completed_sessions', 30)
            ->where('totals.total_ticks', (30 * 90_000_000) + (5 * 50_000_000))
            ->where('totals.top_user.name', 'heavy')
            ->where('totals.top_user.ticks', 30 * 90_000_000)
            ->where('totals.top_user.sessions', 30)
        );
});

test('totals respect viewer scope', function (): void {
    $viewer = User::factory()->create();
    $myLink = EmbyUserLink::factory()->create(['user_id' => $viewer->id, 'emby_username' => 'me']);
    $otherLink = EmbyUserLink::factory()->create(['emby_username' => 'them']);

    EmbyActivity::factory()->count(2)->create([
        'emby_user_link_id' => $myLink->id,
        'duration_ticks' => 100,
        'play_position' => 80,
    ]);
    EmbyActivity::factory()->count(10)->create([
        'emby_user_link_id' => $otherLink->id,
        'duration_ticks' => 100,
        'play_position' => 95,
    ]);

    $this->actingAs($viewer)
        ->get(route('monitoring.watch-history'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('totals.sessions', 2)
            ->where('totals.total_ticks', 160)
            ->where('totals.top_user.name', 'me')
        );
});

test('totals respect media_type filter', function (): void {
    $admin = User::factory()->admin()->create();
    $link = EmbyUserLink::factory()->create();

    EmbyActivity::factory()->count(3)->create([
        'emby_user_link_id' => $link->id,
        'media_type' => 'movie',
        'duration_ticks' => 100,
        'play_position' => 100,
    ]);
    EmbyActivity::factory()->count(5)->create([
        'emby_user_link_id' => $link->id,
        'media_type' => 'episode',
        'duration_ticks' => 100,
        'play_position' => 50,
    ]);

    $this->actingAs($admin)
        ->get(route('monitoring.watch-history', ['media_type' => 'movie']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('totals.sessions', 3)
            ->where('totals.completed_sessions', 3)
            ->where('totals.total_ticks', 300)
        );
});
