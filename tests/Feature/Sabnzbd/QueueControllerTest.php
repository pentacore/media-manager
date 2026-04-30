<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Sabnzbd\SabnzbdDownloadCounter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    $this->user = User::factory()->member()->create();
    // Pre-warm the SAB download counter cache so the shared Inertia
    // middleware doesn't double-fetch SAB on every page render.
    Cache::put(SabnzbdDownloadCounter::CACHE_KEY, ['queued' => 0, 'completed' => 0], 60);
});

test('guests are redirected from the queue page', function (): void {
    $this->get(route('sabnzbd.queue.index'))->assertRedirect(route('login'));
});

test('queue index renders empty state without an active connection', function (): void {
    $this->actingAs($this->user)
        ->get(route('sabnzbd.queue.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Sabnzbd/Queue/Index')
            ->where('configured', false)
        );
});

test('queue index pulls live queue + history when configured', function (): void {
    ServiceConnection::factory()->sabnzbd()->create([
        'url' => 'http://sab.local:8080',
        'api_key' => 'k',
    ]);

    Http::fake([
        'sab.local:8080/api*' => Http::sequence()
            ->push(['queue' => ['paused' => false, 'slots' => [['nzo_id' => 'x', 'filename' => 'foo']]]])
            ->push(['history' => ['slots' => []]]),
    ]);

    $this->actingAs($this->user)
        ->get(route('sabnzbd.queue.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Sabnzbd/Queue/Index')
            ->where('configured', true)
            ->where('paused', false)
            ->has('queue.slots', 1)
        );
});

test('hidden_categories filters out matching slots from queue and history', function (): void {
    ServiceConnection::factory()->sabnzbd()->create([
        'url' => 'http://sab.local:8080',
        'api_key' => 'k',
        'settings' => ['hidden_categories' => ['adult', 'private']],
    ]);

    Http::fake([
        'sab.local:8080/api*' => Http::sequence()
            ->push([
                'queue' => [
                    'paused' => false,
                    'slots' => [
                        ['nzo_id' => 'a', 'filename' => 'visible.nzb', 'cat' => 'movies'],
                        ['nzo_id' => 'b', 'filename' => 'hidden.nzb', 'cat' => 'adult'],
                        ['nzo_id' => 'c', 'filename' => 'untagged.nzb'],
                    ],
                ],
            ])
            ->push([
                'history' => [
                    'slots' => [
                        ['nzo_id' => 'h1', 'name' => 'visible-history', 'category' => 'tv'],
                        ['nzo_id' => 'h2', 'name' => 'hidden-history', 'category' => 'private'],
                    ],
                ],
            ]),
    ]);

    $this->actingAs($this->user)
        ->get(route('sabnzbd.queue.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('queue.slots', 2)
            ->where('queue.slots.0.nzo_id', 'a')
            ->where('queue.slots.1.nzo_id', 'c')
            ->has('history.slots', 1)
            ->where('history.slots.0.nzo_id', 'h1')
        );
});

test('admin can persist hidden_categories on a sabnzbd connection', function (): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->sabnzbd()->create([
        'url' => 'http://sab.local:8080',
        'api_key' => 'k',
    ]);

    $response = $this->actingAs($admin)
        ->put(route('admin.connections.update', $connection), [
            'type' => 'sabnzbd',
            'name' => $connection->name,
            'url' => $connection->url,
            'hidden_categories' => ['adult', 'private'],
        ]);

    $response->assertRedirect(route('admin.connections.index'));

    expect($connection->fresh()->settings['hidden_categories'])
        ->toBe(['adult', 'private']);
});

test('pauseSlot endpoint logs activity and flashes a toast', function (): void {
    $connection = ServiceConnection::factory()->sabnzbd()->create([
        'url' => 'http://sab.local:8080',
        'api_key' => 'k',
    ]);

    Http::fake([
        'sab.local:8080/api*' => Http::response(['status' => true]),
    ]);

    $this->actingAs($this->user)
        ->from(route('sabnzbd.queue.index'))
        ->post(route('sabnzbd.queue.slot.pause', ['nzoId' => 'NZO-9']))
        ->assertRedirect(route('sabnzbd.queue.index'));

    $activityLog = ActivityLog::query()->where('action', 'sabnzbd.slot.paused')->firstOrFail();
    expect($activityLog->service_connection_id)->toBe($connection->id);
    expect($activityLog->metadata['nzo_id'])->toBe('NZO-9');
});

test('reprioritize validates priority is in the SABnzbd range', function (): void {
    ServiceConnection::factory()->sabnzbd()->create([
        'url' => 'http://sab.local:8080',
        'api_key' => 'k',
    ]);

    $this->actingAs($this->user)
        ->from(route('sabnzbd.queue.index'))
        ->patch(route('sabnzbd.queue.slot.priority', ['nzoId' => 'X']), ['priority' => 99])
        ->assertSessionHasErrors('priority');
});

test('reprioritize accepts valid priority and writes activity', function (): void {
    ServiceConnection::factory()->sabnzbd()->create([
        'url' => 'http://sab.local:8080',
        'api_key' => 'k',
    ]);

    Http::fake([
        'sab.local:8080/api*' => Http::response(['status' => true]),
    ]);

    $this->actingAs($this->user)
        ->from(route('sabnzbd.queue.index'))
        ->patch(route('sabnzbd.queue.slot.priority', ['nzoId' => 'NZO-7']), ['priority' => 1])
        ->assertRedirect();

    $activityLog = ActivityLog::query()->where('action', 'sabnzbd.slot.reprioritized')->firstOrFail();
    expect($activityLog->metadata['priority'])->toBe(1);
});
