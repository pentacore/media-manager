<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Sabnzbd\SabnzbdDownloadCounter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

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

test('a successful queue load clears the error a failed poll left behind', function (): void {
    ServiceConnection::factory()->sabnzbd()->create(['url' => 'http://sab.local:8080', 'api_key' => 'k']);
    Http::fake(['sab.local:8080/api*' => Http::sequence()
        ->push(['queue' => ['paused' => false, 'slots' => []]])
        ->push(['history' => ['slots' => []]])]);

    $this->actingAs($this->user)
        ->get(route('sabnzbd.queue.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('configured', true)
            ->where('connection.name', fn (string $name): bool => $name !== '')
            ->where('error', null));
});

test('without a connection the queue page carries no error', function (): void {
    $this->actingAs($this->user)
        ->get(route('sabnzbd.queue.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('configured', false)->where('error', null));
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
            ->where('queue.hidden_by_category', 1)
            ->has('history.slots', 1)
            ->where('history.slots.0.nzo_id', 'h1')
            ->where('history.hidden_by_category', 1)
        );
});

test('a history page whose slots are all hidden says so and keeps the SABnzbd total', function (): void {
    ServiceConnection::factory()->sabnzbd()->create([
        'url' => 'http://sab.local:8080',
        'api_key' => 'k',
        'settings' => ['hidden_categories' => ['adult']],
    ]);

    Http::fake([
        'sab.local:8080/api*' => Http::sequence()
            ->push(['queue' => ['paused' => false, 'noofslots' => 0, 'slots' => []]])
            ->push(['history' => [
                'noofslots' => 40,
                'slots' => [
                    ['nzo_id' => 'h1', 'name' => 'example-one', 'category' => 'adult'],
                    ['nzo_id' => 'h2', 'name' => 'example-two', 'category' => 'adult'],
                ],
            ]]),
    ]);

    $this->actingAs($this->user)
        ->get(route('sabnzbd.queue.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('history.slots', 0)
            ->where('history.total', 40)
            ->where('history.hidden_by_category', 2)
            ->where('queue.hidden_by_category', 0)
        );
});

test('without hidden categories nothing is counted as hidden', function (): void {
    ServiceConnection::factory()->sabnzbd()->create(['url' => 'http://sab.local:8080', 'api_key' => 'k']);

    Http::fake([
        'sab.local:8080/api*' => Http::sequence()
            ->push(['queue' => ['paused' => false, 'slots' => [['nzo_id' => 'a', 'filename' => 'example.nzb', 'cat' => 'adult']]]])
            ->push(['history' => ['noofslots' => 1, 'slots' => [['nzo_id' => 'h1', 'name' => 'example', 'category' => 'adult']]]]),
    ]);

    $this->actingAs($this->user)
        ->get(route('sabnzbd.queue.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('queue.slots', 1)
            ->where('queue.hidden_by_category', 0)
            ->has('history.slots', 1)
            ->where('history.hidden_by_category', 0)
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
        ->post(route('sabnzbd.queue.slot.pause', ['nzoId' => 'SABnzbd_nzo_9']))
        ->assertRedirect(route('sabnzbd.queue.index'));

    $activityLog = ActivityLog::query()->where('action', 'sabnzbd.slot.paused')->firstOrFail();
    expect($activityLog->service_connection_id)->toBe($connection->id);
    expect($activityLog->metadata['nzo_id'])->toBe('SABnzbd_nzo_9');
});

test('reprioritize validates priority is in the SABnzbd range', function (): void {
    ServiceConnection::factory()->sabnzbd()->create([
        'url' => 'http://sab.local:8080',
        'api_key' => 'k',
    ]);

    $this->actingAs($this->user)
        ->from(route('sabnzbd.queue.index'))
        ->patch(route('sabnzbd.queue.slot.priority', ['nzoId' => 'SABnzbd_nzo_x']), ['priority' => 99])
        ->assertSessionHasErrors('priority');
});

test('reprioritize accepts valid priority and writes activity', function (): void {
    ServiceConnection::factory()->sabnzbd()->create([
        'url' => 'http://sab.local:8080',
        'api_key' => 'k',
    ]);

    Http::fake([
        'sab.local:8080/api*' => Http::response(['position' => 1]),
    ]);

    $this->actingAs($this->user)
        ->from(route('sabnzbd.queue.index'))
        ->patch(route('sabnzbd.queue.slot.priority', ['nzoId' => 'SABnzbd_nzo_7']), ['priority' => 1])
        ->assertRedirect();

    $activityLog = ActivityLog::query()->where('action', 'sabnzbd.slot.reprioritized')->firstOrFail();
    expect($activityLog->metadata['priority'])->toBe(1);
});

test('a queue page SABnzbd refuses shows the refusal instead of empty lists', function (mixed $body): void {
    ServiceConnection::factory()->sabnzbd()->create(['url' => 'http://sab.local:8080', 'api_key' => 'wrong-key']);
    Http::fake(['sab.local:8080/api*' => Http::response($body, 200, is_string($body) ? ['Content-Type' => 'text/html'] : [])]);

    $this->actingAs($this->user)
        ->get(route('sabnzbd.queue.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Sabnzbd/Queue/Index')
            ->where('configured', true)
            ->where('queue', [])
            ->where('history.slots', [])
            ->where('error', 'SABnzbd is unavailable — ask an admin to check the connection.'));
})->with([
    'wrong api key' => [['status' => false, 'error' => 'API Key Incorrect']],
    'login page' => ['<html><body>Sign in</body></html>'],
    'queue section is not an object' => [['queue' => 'busy']],
    'no queue section' => [['version' => '4.2.0']],
]);

test('an admin is sent to the connection settings when SABnzbd refuses', function (): void {
    ServiceConnection::factory()->sabnzbd()->create(['url' => 'http://sab.local:8080', 'api_key' => 'wrong-key']);
    Http::fake(['sab.local:8080/api*' => Http::response(['status' => false, 'error' => 'API Key Incorrect'])]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('sabnzbd.queue.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Sabnzbd/Queue/Index')
            ->where('error', 'SABnzbd refused the request — check the connection settings.'));
});

test('a queue page SABnzbd cannot be reached for still says it could not reach SABnzbd', function (): void {
    Sleep::fake();
    ServiceConnection::factory()->sabnzbd()->create(['url' => 'http://sab.local:8080', 'api_key' => 'k']);
    Http::fake(['sab.local:8080/api*' => Http::response('Service Unavailable', 503)]);

    $this->actingAs($this->user)
        ->get(route('sabnzbd.queue.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('error', 'Could not reach SABnzbd.'));
});

test('an HTTP refusal in front of SABnzbd reads as a refusal worded for the viewer', function (string $role, string $error): void {
    Sleep::fake();
    ServiceConnection::factory()->sabnzbd()->create(['url' => 'http://sab.local:8080', 'api_key' => 'k']);
    Http::fake(['sab.local:8080/api*' => Http::response('Forbidden', 403)]);

    $this->actingAs(User::factory()->{$role}()->create())
        ->get(route('sabnzbd.queue.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Sabnzbd/Queue/Index')
            ->where('queue', [])
            ->where('error', $error));
})->with([
    'admin' => ['admin', 'SABnzbd refused the request — check the connection settings.'],
    'member' => ['member', 'SABnzbd is unavailable — ask an admin to check the connection.'],
]);

test('a dropped connection to SABnzbd still says it could not reach SABnzbd for every role', function (string $role): void {
    Sleep::fake();
    ServiceConnection::factory()->sabnzbd()->create(['url' => 'http://sab.local:8080', 'api_key' => 'k']);
    Http::fake(['sab.local:8080/api*' => fn () => throw new ConnectionException('Connection refused')]);

    $this->actingAs(User::factory()->{$role}()->create())
        ->get(route('sabnzbd.queue.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('error', 'Could not reach SABnzbd.'));
})->with(['admin', 'member']);

test('a priority change SABnzbd does not place in the queue is refused and writes nothing', function (): void {
    ServiceConnection::factory()->sabnzbd()->create(['url' => 'http://sab.local:8080', 'api_key' => 'k']);
    Http::fake(['sab.local:8080/api*' => Http::response(['position' => -1])]);

    $this->actingAs($this->user)
        ->from(route('sabnzbd.queue.index'))
        ->patch(route('sabnzbd.queue.slot.priority', ['nzoId' => 'SABnzbd_nzo_7']), ['priority' => 1])
        ->assertRedirect(route('sabnzbd.queue.index'))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => 'SABnzbd refused the change.']);

    expect(ActivityLog::query()->where('action', 'sabnzbd.slot.reprioritized')->exists())->toBeFalse();
});

test('a SABnzbd outage during a queue pause is reported with the failure toast', function (): void {
    Sleep::fake();
    ServiceConnection::factory()->sabnzbd()->create(['url' => 'http://sab.local:8080', 'api_key' => 'k']);
    Http::fake(['sab.local:8080/api*' => fn () => throw new ConnectionException('Connection refused')]);

    $this->actingAs($this->user)
        ->from(route('sabnzbd.queue.index'))
        ->post(route('sabnzbd.queue.pause'))
        ->assertSessionHas('inertia.flash_data.toast', ['type' => 'error', 'message' => 'Failed to pause queue.']);
});

test('a failure writing the queue pause activity row surfaces instead of reading as a SABnzbd failure', function (): void {
    ServiceConnection::factory()->sabnzbd()->create(['url' => 'http://sab.local:8080', 'api_key' => 'k']);
    Http::fake(['sab.local:8080/api*' => Http::response(['status' => true])]);
    ActivityLog::creating(static function (): void {
        throw new RuntimeException('activity store unavailable');
    });

    expect(fn () => $this->withoutExceptionHandling()
        ->actingAs($this->user)
        ->post(route('sabnzbd.queue.pause')))
        ->toThrow(RuntimeException::class, 'activity store unavailable');
});
