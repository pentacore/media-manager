<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\MediaReplacementAttempt;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Cache::flush();
});

/**
 * The page object of an Inertia visit.
 *
 * @return array<string, mixed>
 */
function adminLivePage(string $url): array
{
    return test()->actingAs(User::factory()->admin()->create())
        ->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) new HandleInertiaRequests()->version(request())])
        ->assertOk()
        ->json();
}

/**
 * @param  array<string, mixed>  $page
 * @param  list<string>  $events
 */
function expectAdminLiveProp(array $page, string $prop, string $channel, array $events): void
{
    expect($page['liveProps'][$prop]['listeners'])->toBe([[
        'channel' => ['name' => $channel, 'type' => 'private'],
        'events' => $events,
    ]]);
}

test('the servers list is live on the servers channel', function (): void {
    Server::factory()->create();

    $page = adminLivePage(route('admin.servers.index', absolute: false));

    expectAdminLiveProp($page, 'servers', 'admin.servers', ['ServerReported', 'ServerIncidentChanged']);
    expect($page['liveProps'])->toHaveCount(1);
});

test('a server page is live on its own channel, and only the reported props ignore incident changes', function (): void {
    $server = Server::factory()->create();
    $channel = 'admin.servers.'.$server->id;

    $page = adminLivePage(route('admin.servers.show', $server, absolute: false));

    expectAdminLiveProp($page, 'server', $channel, ['ServerReported', 'ServerIncidentChanged']);
    expectAdminLiveProp($page, 'incidents', $channel, ['ServerReported', 'ServerIncidentChanged']);
    expectAdminLiveProp($page, 'latest', $channel, ['ServerReported']);
    expectAdminLiveProp($page, 'checks', $channel, ['ServerReported']);
    expectAdminLiveProp($page, 'metrics', $channel, ['ServerReported']);
    expectAdminLiveProp($page, 'range', $channel, ['ServerReported']);
    expect($page['liveProps'])->toHaveCount(6);
});

test('the incident list is live on the servers channel for incident changes', function (): void {
    $page = adminLivePage(route('admin.servers.incidents.index', absolute: false));

    expectAdminLiveProp($page, 'incidents', 'admin.servers', ['ServerIncidentChanged']);
    expect($page['liveProps'])->toHaveCount(1);
});

test('the replacement attempts list is live on the replacement channel', function (): void {
    MediaReplacementAttempt::factory()->verified()->create();

    $page = adminLivePage(route('admin.media-replacement.attempts.index', absolute: false));

    expectAdminLiveProp($page, 'attempts', 'admin.media-replacement', ['MediaReplacementAttemptChanged']);
    expectAdminLiveProp($page, 'statusCounts', 'admin.media-replacement', ['MediaReplacementAttemptChanged']);
    expect($page['liveProps'])->toHaveCount(2);
});

test('a replacement attempt page is live on its own channel', function (): void {
    $attempt = MediaReplacementAttempt::factory()->verified()->create();

    $page = adminLivePage(route('admin.media-replacement.attempts.show', $attempt, absolute: false));

    expectAdminLiveProp($page, 'attempt', 'admin.media-replacement.'.$attempt->id, ['MediaReplacementAttemptChanged']);
    expect($page['liveProps'])->toHaveCount(1);
});
