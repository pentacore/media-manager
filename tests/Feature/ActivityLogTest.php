<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
});

test('guests are redirected to login', function (): void {
    $this->get(route('activity-log'))->assertRedirect(route('login'));
});

test('authenticated users see paginated activity entries', function (): void {
    $user = User::factory()->create();
    $connection = ServiceConnection::factory()->create();

    ActivityLog::factory()->count(3)->create([
        'user_id' => $user->id,
        'service_connection_id' => $connection->id,
    ]);

    $this->actingAs($user)
        ->get(route('activity-log'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ActivityLog')
            ->has('logs.data', 3)
            ->has('logs.data.0', fn ($log) => $log
                ->where('user_name', $user->name)
                ->where('service_name', $connection->name)
                ->where('service_type', $connection->type->value)
                ->etc()
            )
            ->where('logs.meta.total', 3)
            ->where('logs.meta.current_page', 1)
            ->has('filterOptions.actions')
            ->has('filterOptions.services', 1)
        );
});

test('action filter scopes the result set', function (): void {
    $user = User::factory()->create();
    ActivityLog::factory()->create(['user_id' => $user->id, 'action' => 'created']);
    ActivityLog::factory()->create(['user_id' => $user->id, 'action' => 'deleted']);
    ActivityLog::factory()->create(['user_id' => $user->id, 'action' => 'deleted']);

    $this->actingAs($user)
        ->get(route('activity-log', ['action' => 'deleted']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ActivityLog')
            ->has('logs.data', 2)
            ->where('filters.action', 'deleted')
        );
});

test('since=today only returns rows from the local current day', function (): void {
    $user = User::factory()->create();

    CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 4, 29, 14, 30));

    ActivityLog::factory()->create([
        'user_id' => $user->id,
        'created_at' => CarbonImmutable::create(2026, 4, 29, 0, 5),
    ]);
    ActivityLog::factory()->create([
        'user_id' => $user->id,
        'created_at' => CarbonImmutable::create(2026, 4, 28, 23, 30),
    ]);

    $this->actingAs($user)
        ->get(route('activity-log', ['since' => 'today']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ActivityLog')
            ->where('filters.since', 'today')
            ->where('logs.meta.total', 1)
            ->where('filterOptions.todayValue', 'today')
        );

    CarbonImmutable::setTestNow();
});

test('unknown since value falls back to default 24h', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('activity-log', ['since' => 'forever']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.since', 24));
});

test('service filter scopes the result set', function (): void {
    $user = User::factory()->create();
    $connectionA = ServiceConnection::factory()->create();
    $connectionB = ServiceConnection::factory()->create();

    ActivityLog::factory()->count(2)->create([
        'user_id' => $user->id,
        'service_connection_id' => $connectionA->id,
    ]);
    ActivityLog::factory()->create([
        'user_id' => $user->id,
        'service_connection_id' => $connectionB->id,
    ]);

    $this->actingAs($user)
        ->get(route('activity-log', ['service_id' => $connectionA->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('ActivityLog')
            ->has('logs.data', 2)
            ->where('filters.service_id', $connectionA->id)
        );
});
