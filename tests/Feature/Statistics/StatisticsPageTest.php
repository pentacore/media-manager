<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
});

it('requires authentication', function (): void {
    $this->get(route('statistics.index'))->assertRedirect(route('login'));
});

it('renders the statistics page with headline props', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('statistics.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Statistics/Index')
            ->has('headline')
            ->has('headline.plays')
            ->has('headline.finishes')
            ->has('headline.watchHours')
            ->has('headline.downloads')
            ->has('windows')
            ->where('window', '30d')
            ->has('watchSeries')
            ->has('downloadSeries')
            ->has('librarySeries')
            ->has('requestFunnel'));
});

it('honours the window query parameter', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('statistics.index', ['window' => '7d']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Statistics/Index')
            ->where('window', '7d'));
});

it('exposes deferred leaderboard, top titles, and heatmap props', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('statistics.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Statistics/Index')
            ->loadDeferredProps(fn ($page) => $page
                ->has('leaderboard')
                ->has('topTitles')
                ->has('hourHeatmap')));
});
