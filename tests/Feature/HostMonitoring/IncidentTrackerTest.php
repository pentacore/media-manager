<?php

declare(strict_types=1);

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Events\ServerIncidentChanged;
use App\Models\NotificationDestination;
use App\Models\Server;
use App\Models\ServerIncident;
use App\Models\User;
use App\Notifications\HostIncidentEscalated;
use App\Notifications\HostIncidentOpened;
use App\Notifications\HostIncidentResolved;
use App\Services\HostMonitoring\IncidentTracker;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    Event::fake([ServerIncidentChanged::class]);

    $this->admin = User::factory()->admin()->create();
    $this->member = User::factory()->member()->create();
    $this->server = Server::factory()->enrolled()->create();
    $this->tracker = resolve(IncidentTracker::class);
});

test('observing a new key opens one incident and notifies admins once', function (): void {
    $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'Disk / is 86% full', 'a');
    $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'Disk / is 87% full', 'b');

    expect(ServerIncident::query()->sole())
        ->status->toBe(IncidentStatus::Open)
        ->detail->toBe('b')
        ->title->toBe('Disk / is 87% full')
        ->notified_severity->toBe(IncidentSeverity::Warning)
        ->clear_streak->toBe(0);

    Notification::assertSentToTimes($this->admin, HostIncidentOpened::class, 1);
    Notification::assertNotSentTo($this->member, HostIncidentOpened::class);
    Event::assertDispatchedTimes(ServerIncidentChanged::class, 1);
});

test('a detail-only update refreshes last seen and context without notifying or broadcasting', function (): void {
    $this->travelTo(now()->startOfMinute());
    $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'Disk / is 86% full', 'a', ['percent' => 86]);

    $this->travel(5)->minutes();
    $incident = $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'Disk / is 88% full', 'b', ['percent' => 88]);

    expect($incident->context)->toBe(['percent' => 88])
        ->and($incident->last_seen_at->equalTo(now()))->toBeTrue()
        ->and($incident->opened_at->equalTo(now()->subMinutes(5)))->toBeTrue();

    Notification::assertSentToTimes($this->admin, HostIncidentOpened::class, 1);
    Event::assertDispatchedTimes(ServerIncidentChanged::class, 1);
});

test('a warning that turns critical escalates loudly once', function (): void {
    $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'Disk / is 86% full', null);
    $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Critical, 'Disk / is 96% full', null);
    $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Critical, 'Disk / is 97% full', null);

    expect(ServerIncident::query()->sole())
        ->severity->toBe(IncidentSeverity::Critical)
        ->notified_severity->toBe(IncidentSeverity::Critical)
        ->escalated_at->not->toBeNull();

    Notification::assertSentToTimes($this->admin, HostIncidentOpened::class, 1);
    Notification::assertSentToTimes($this->admin, HostIncidentEscalated::class, 1);
    Event::assertDispatchedTimes(ServerIncidentChanged::class, 2);
});

test('critical falling back to warning updates silently and re-rising does not re-escalate', function (): void {
    $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'w', null);
    $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Critical, 'c', null);
    $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'w', null);

    expect(ServerIncident::query()->sole()->severity)->toBe(IncidentSeverity::Warning);
    Notification::assertSentToTimes($this->admin, HostIncidentEscalated::class, 1);
    Event::assertDispatchedTimes(ServerIncidentChanged::class, 3);

    $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Critical, 'c', null);

    expect(ServerIncident::query()->sole()->severity)->toBe(IncidentSeverity::Critical);
    Notification::assertSentToTimes($this->admin, HostIncidentEscalated::class, 1);
    Event::assertDispatchedTimes(ServerIncidentChanged::class, 4);
});

test('an incident opened as critical sends opened with error severity and never escalates', function (): void {
    $this->tracker->observe($this->server, 'silent', IncidentSeverity::Critical, 'babel went silent', null);
    $this->tracker->observe($this->server, 'silent', IncidentSeverity::Critical, 'babel is still silent', null);

    Notification::assertSentToTimes($this->admin, HostIncidentOpened::class, 1);
    Notification::assertNotSentTo($this->admin, HostIncidentEscalated::class);
    Notification::assertSentTo($this->admin, HostIncidentOpened::class, fn (HostIncidentOpened $notification): bool => $notification->toPush($this->admin)->severity === 'error');
});

test('two consecutive clears resolve and notify', function (): void {
    $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'Disk / is 86% full', null);

    $first = $this->tracker->clear($this->server, 'disk:/');

    expect($first)->not->toBeNull()
        ->and($first->status)->toBe(IncidentStatus::Open)
        ->and($first->clear_streak)->toBe(1);
    Notification::assertNotSentTo($this->admin, HostIncidentResolved::class);

    $second = $this->tracker->clear($this->server, 'disk:/');

    expect($second->status)->toBe(IncidentStatus::Resolved)
        ->and($second->resolved_at)->not->toBeNull();
    Notification::assertSentToTimes($this->admin, HostIncidentResolved::class, 1);
    Event::assertDispatchedTimes(ServerIncidentChanged::class, 2);
});

test('an observation between clears resets the streak', function (): void {
    $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'Disk / is 86% full', null);
    $this->tracker->clear($this->server, 'disk:/');
    $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'Disk / is 86% full', null);
    $this->tracker->clear($this->server, 'disk:/');

    expect(ServerIncident::query()->sole())
        ->status->toBe(IncidentStatus::Open)
        ->clear_streak->toBe(1);
    Notification::assertNotSentTo($this->admin, HostIncidentResolved::class);
});

test('a single clear resolves when one clear is required', function (): void {
    $this->tracker->observe($this->server, 'silent', IncidentSeverity::Critical, 'babel went silent', null);

    $incident = $this->tracker->clear($this->server, 'silent', 1);

    expect($incident->status)->toBe(IncidentStatus::Resolved);
    Notification::assertSentToTimes($this->admin, HostIncidentResolved::class, 1);
});

test('clearing a key with nothing open returns null and sends nothing', function (): void {
    expect($this->tracker->clear($this->server, 'disk:/'))->toBeNull();

    $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'x', null);
    $this->tracker->clear($this->server, 'disk:/', 1);

    expect($this->tracker->clear($this->server, 'disk:/', 1))->toBeNull();
    Notification::assertSentToTimes($this->admin, HostIncidentResolved::class, 1);
});

test('a resolved key opens a fresh incident when it recurs', function (): void {
    $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'x', null);
    $this->tracker->clear($this->server, 'disk:/', 1);
    $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'x', null);

    expect(ServerIncident::query()->count())->toBe(2)
        ->and(ServerIncident::query()->open()->count())->toBe(1);
    Notification::assertSentToTimes($this->admin, HostIncidentOpened::class, 2);
});

test('incidents are tracked per server and key', function (): void {
    $other = Server::factory()->enrolled()->create();

    $this->tracker->observe($this->server, 'silent', IncidentSeverity::Critical, 'a', null);
    $this->tracker->observe($other, 'silent', IncidentSeverity::Critical, 'b', null);
    $this->tracker->clear($this->server, 'silent', 1);

    expect(ServerIncident::query()->open()->sole()->server_id)->toBe($other->id);
});

test('a title longer than the column is cut to 255 characters', function (): void {
    $incident = $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, str_repeat('a', 1024), null);

    expect(mb_strlen($incident->fresh()->title))->toBe(255);
});

test('the broadcast carries the incident identity and state', function (): void {
    $incident = $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'Disk / is 86% full', null);

    Event::assertDispatched(function (ServerIncidentChanged $event) use ($incident): bool {
        $payload = $event->broadcastWith();

        return $payload['server_id'] === $this->server->id
            && $payload['incident_id'] === $incident->id
            && $payload['key'] === 'disk:/'
            && $payload['status'] === 'open'
            && $payload['severity'] === 'warning'
            && $payload['title'] === 'Disk / is 86% full'
            && is_string($payload['occurred_at']);
    });
});

test('enabled notification destinations receive incidents through the admin fan-out', function (): void {
    $destination = NotificationDestination::factory()->ntfy()->create();

    $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'Disk / is 86% full', null);

    Notification::assertSentTo($destination, HostIncidentOpened::class);
});
