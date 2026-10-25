<?php

declare(strict_types=1);

use App\Enums\IncidentSeverity;
use App\Models\NotificationPreference;
use App\Models\Server;
use App\Models\ServerIncident;
use App\Models\User;
use App\Notifications\HostIncidentEscalated;
use App\Notifications\HostIncidentNotification;
use App\Notifications\HostIncidentOpened;
use App\Notifications\HostIncidentResolved;
use App\Services\Notifications\PushMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;

function incidentFor(IncidentSeverity $severity = IncidentSeverity::Warning): ServerIncident
{
    $server = Server::query()->firstOr(fn (): Server => Server::factory()->enrolled()->create(['name' => 'babel']));

    return ServerIncident::factory()->for($server)->create([
        'severity' => $severity,
        'title' => 'Disk / is 86% full',
        'detail' => '86% of 100 GB used',
        'opened_at' => now()->subMinutes(90),
    ]);
}

test('severities map warning to warning, critical to error and resolved to warning', function (): void {
    $user = User::factory()->create();
    $serverIncident = incidentFor();
    $critical = incidentFor(IncidentSeverity::Critical);

    expect(HostIncidentOpened::fromIncident($serverIncident)->toPush($user)->severity)->toBe('warning')
        ->and(HostIncidentOpened::fromIncident($critical)->toPush($user)->severity)->toBe('error')
        ->and(HostIncidentEscalated::fromIncident($critical)->toPush($user)->severity)->toBe('error')
        ->and(HostIncidentResolved::fromIncident($serverIncident)->toPush($user)->severity)->toBe('warning')
        ->and(HostIncidentResolved::fromIncident($critical)->toPush($user)->severity)->toBe('warning');
});

test('titles and bodies read per notification kind', function (): void {
    $user = User::factory()->create();
    $serverIncident = incidentFor();

    $opened = HostIncidentOpened::fromIncident($serverIncident)->toArray($user);
    $escalated = HostIncidentEscalated::fromIncident($serverIncident)->toArray($user);
    $resolved = HostIncidentResolved::fromIncident($serverIncident)->toArray($user);

    expect($opened['title'])->toBe('[babel] Disk / is 86% full')
        ->and($opened['message'])->toBe('86% of 100 GB used')
        ->and($opened['service'])->toBe('system')
        ->and($opened['server_id'])->toBe($serverIncident->server_id)
        ->and($opened['incident_id'])->toBe($serverIncident->id)
        ->and($opened['severity'])->toBe('warning')
        ->and($escalated['title'])->toBe('[babel] Now critical: Disk / is 86% full')
        ->and($resolved['title'])->toBe('[babel] Resolved: Disk / is 86% full')
        ->and($resolved['message'])->toBe('Resolved after 1 hour 30 minutes.');
});

test('the opened body falls back to the title without a detail', function (): void {
    $serverIncident = incidentFor();
    $serverIncident->update(['detail' => null]);

    expect(HostIncidentOpened::fromIncident($serverIncident)->toArray(User::factory()->create())['message'])->toBe('Disk / is 86% full');
});

test('the push links to the incident on the server page', function (): void {
    $user = User::factory()->create();
    $serverIncident = incidentFor();
    $pushMessage = HostIncidentOpened::fromIncident($serverIncident)->toPush($user);

    expect($pushMessage)->toBeInstanceOf(PushMessage::class)
        ->and($pushMessage->url)->toStartWith(config('app.url'))
        ->and($pushMessage->url)->toEndWith(sprintf('/admin/servers/%d#incident-%d', $serverIncident->server_id, $serverIncident->id));
});

test('the notification snapshots the incident so a later change does not alter it', function (): void {
    $serverIncident = incidentFor();
    $hostIncidentOpened = HostIncidentOpened::fromIncident($serverIncident);

    $serverIncident->update(['title' => 'changed']);

    expect($hostIncidentOpened->toArray(User::factory()->create())['title'])->toBe('[babel] Disk / is 86% full');
});

test('broadcast mirrors the array payload', function (): void {
    $user = User::factory()->create();
    $hostIncidentOpened = HostIncidentOpened::fromIncident(incidentFor());

    $broadcastMessage = $hostIncidentOpened->toBroadcast($user);

    expect($broadcastMessage)->toBeInstanceOf(BroadcastMessage::class)
        ->and($broadcastMessage->data)->toBe($hostIncidentOpened->toArray($user));
});

test('via defaults to database and broadcast and honours preference rows', function (): void {
    $user = User::factory()->create();
    $hostIncidentOpened = HostIncidentOpened::fromIncident(incidentFor());

    expect($hostIncidentOpened)->toBeInstanceOf(HostIncidentNotification::class)
        ->and($hostIncidentOpened->via($user))->toEqual(['database', 'broadcast']);

    NotificationPreference::create([
        'user_id' => $user->id, 'notification_class' => HostIncidentOpened::class, 'severity' => 'warning',
        'database' => true, 'broadcast' => false, 'mail' => false, 'ntfy' => false,
    ]);

    expect($hostIncidentOpened->via($user))->toEqual(['database']);
});

test('admins see the three server incident rows in notification preferences', function (): void {
    config()->set('inertia.testing.ensure_pages_exist', false);
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('settings.notifications.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('catalog', function ($catalog): bool {
            $labels = collect($catalog)->pluck('label');

            return $labels->contains('Server incident opened')
                && $labels->contains('Server incident escalated')
                && $labels->contains('Server incident resolved');
        }));
});

test('members do not see or save server incident preferences', function (): void {
    config()->set('inertia.testing.ensure_pages_exist', false);
    $member = User::factory()->member()->create();
    $this->actingAs($member);

    $this->get(route('settings.notifications.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('catalog', fn ($catalog): bool => collect($catalog)->pluck('label')->doesntContain('Server incident opened')));

    $this->put(route('settings.notifications.update'), [
        'preferences' => [[
            'class' => HostIncidentOpened::class,
            'severities' => ['warning' => ['database' => true, 'broadcast' => true, 'mail' => true, 'ntfy' => false, 'discord' => false, 'telegram' => false, 'webhook' => false]],
        ]],
    ])->assertRedirect();

    expect(NotificationPreference::query()->where('user_id', $member->id)->count())->toBe(0);
});
