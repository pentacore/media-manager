<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
    // Deactivated, not missing: an inactive connection must count as none.
    ServiceConnection::factory()->sabnzbd()->inactive()->create(['url' => 'http://sab.local:8080']);
});

test('the queue page renders as not configured without an active SABnzbd', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('sabnzbd.queue.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Sabnzbd/Queue/Index')
            ->where('configured', false)
            ->where('connection', null)
            ->where('queue', [])
            ->where('paused', false)
            ->where('error', null));

    Http::assertNothingSent();
});

test('a SABnzbd write without an active SABnzbd goes back with its own toast', function (string $method, string $routeName, array $parameters, array $data): void {
    $this->actingAs(User::factory()->admin()->create())
        ->from(route('sabnzbd.queue.index'))
        ->{$method}(route($routeName, $parameters), $data)
        ->assertRedirect(route('sabnzbd.queue.index'))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => 'No SABnzbd connection configured.']);

    Http::assertNothingSent();
})->with([
    'pause the queue' => ['post', 'sabnzbd.queue.pause', [], []],
    'resume the queue' => ['post', 'sabnzbd.queue.resume', [], []],
    'pause a job' => ['post', 'sabnzbd.queue.slot.pause', ['nzoId' => 'SABnzbd_nzo_abc'], []],
    'resume a job' => ['post', 'sabnzbd.queue.slot.resume', ['nzoId' => 'SABnzbd_nzo_abc'], []],
    'delete a job' => ['delete', 'sabnzbd.queue.slot.delete', ['nzoId' => 'SABnzbd_nzo_abc'], []],
    'reprioritize a job' => ['patch', 'sabnzbd.queue.slot.priority', ['nzoId' => 'SABnzbd_nzo_abc'], ['priority' => 1]],
    'set the speed limit' => ['post', 'sabnzbd.speed-limit.update', [], ['value' => '50']],
    'retry a history job' => ['post', 'sabnzbd.history.retry', ['nzoId' => 'SABnzbd_nzo_abc'], []],
    'delete a history job' => ['delete', 'sabnzbd.history.destroy', ['nzoId' => 'SABnzbd_nzo_abc'], ['with_files' => false]],
]);

test('a bulk SABnzbd action without an active SABnzbd is refused as JSON', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->postJson(route('sabnzbd.queue.bulk'), ['ids' => ['SABnzbd_nzo_abc'], 'action' => 'pause'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'No SABnzbd connection configured.');

    Http::assertNothingSent();
});
