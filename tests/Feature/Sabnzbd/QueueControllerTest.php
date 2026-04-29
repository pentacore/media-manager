<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    $this->user = User::factory()->member()->create();
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
        'sab.local:8080/sabnzbd/api*' => Http::sequence()
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

test('pauseSlot endpoint logs activity and flashes a toast', function (): void {
    $connection = ServiceConnection::factory()->sabnzbd()->create([
        'url' => 'http://sab.local:8080',
        'api_key' => 'k',
    ]);

    Http::fake([
        'sab.local:8080/sabnzbd/api*' => Http::response(['status' => true]),
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
        'sab.local:8080/sabnzbd/api*' => Http::response(['status' => true]),
    ]);

    $this->actingAs($this->user)
        ->from(route('sabnzbd.queue.index'))
        ->patch(route('sabnzbd.queue.slot.priority', ['nzoId' => 'NZO-7']), ['priority' => 1])
        ->assertRedirect();

    $activityLog = ActivityLog::query()->where('action', 'sabnzbd.slot.reprioritized')->firstOrFail();
    expect($activityLog->metadata['priority'])->toBe(1);
});
