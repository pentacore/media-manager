<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-09-15T12:00:00Z'));
});

test('the calendar defaults to the current month and defers the merged items', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989']);
    Http::fake(['sonarr.local:8989/api/v3/calendar*' => Http::response([])]);

    $this->actingAs(User::factory()->create())
        ->get(route('media.calendar.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Library/Calendar')
            ->where('month', '2026-09')
            ->missing('calendar')
            ->loadDeferredProps(fn ($reload) => $reload->where('calendar.items', [])->where('calendar.failures', [])));

    Http::assertSent(fn (Request $request): bool => $request['start'] === '2026-08-25T00:00:00Z' && $request['end'] === '2026-10-07T23:59:59Z');
});

test('a chosen month is honoured and a malformed one rejected', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('media.calendar.index', ['month' => '2026-12']))
        ->assertInertia(fn ($page) => $page->where('month', '2026-12'));

    $this->actingAs(User::factory()->create())
        ->get(route('media.calendar.index', ['month' => 'december']))
        ->assertSessionHasErrors('month');
});
