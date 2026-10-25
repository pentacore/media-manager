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
use App\Services\Notifications\AdminNotifier;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;

beforeEach(function (): void {
    Notification::fake();
    Event::fake([ServerIncidentChanged::class]);

    $this->admin = User::factory()->admin()->create();
    $this->member = User::factory()->member()->create();
    $this->server = Server::factory()->enrolled()->create();
    $this->tracker = resolve(IncidentTracker::class);
});

/**
 * One run of the minute sweep that sends the held resolved pushes.
 */
function incidentTrackerSweep(): void
{
    test()->artisan('servers:notify-resolved')->assertSuccessful();
}

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

test('two consecutive clears resolve and broadcast at once but hold the resolved push', function (): void {
    $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'Disk / is 86% full', null);

    $first = $this->tracker->clear($this->server, 'disk:/');

    expect($first)->not->toBeNull()
        ->and($first->status)->toBe(IncidentStatus::Open)
        ->and($first->clear_streak)->toBe(1);

    $second = $this->tracker->clear($this->server, 'disk:/');

    expect($second->status)->toBe(IncidentStatus::Resolved)
        ->and($second->resolved_at)->not->toBeNull()
        ->and($second->resolve_notified_at)->toBeNull();
    Notification::assertNotSentTo($this->admin, HostIncidentResolved::class);
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
    Notification::assertNotSentTo($this->admin, HostIncidentResolved::class);
});

test('clearing a key with nothing open returns null and sends nothing', function (): void {
    expect($this->tracker->clear($this->server, 'disk:/'))->toBeNull();

    $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'x', null);
    $this->tracker->clear($this->server, 'disk:/', 1);

    expect($this->tracker->clear($this->server, 'disk:/', 1))->toBeNull()
        ->and(ServerIncident::query()->sole()->status)->toBe(IncidentStatus::Resolved);
});

test('a stable resolve sends the resolved push once it stayed resolved for the notify delay', function (): void {
    $this->freezeSecond();
    $this->travel(-90)->minutes();
    $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'Disk / is 86% full', null);
    $this->travel(90)->minutes();
    $this->tracker->clear($this->server, 'disk:/', 1);

    $this->travel(9)->minutes();
    incidentTrackerSweep();

    Notification::assertNotSentTo($this->admin, HostIncidentResolved::class);

    $this->travel(1)->minutes();
    incidentTrackerSweep();
    incidentTrackerSweep();

    expect(ServerIncident::query()->sole()->resolve_notified_at)->toEqual(now());
    Notification::assertSentToTimes($this->admin, HostIncidentResolved::class, 1);
    Notification::assertSentTo($this->admin, HostIncidentResolved::class, fn (HostIncidentResolved $notification): bool => $notification->toArray($this->admin)['message'] === 'Resolved after 1 hour 30 minutes.');
    Event::assertDispatchedTimes(ServerIncidentChanged::class, 2);
});

test('the notify delay is configurable', function (): void {
    config(['mediamanager.monitoring.resolve_notify_delay_minutes' => 3]);
    $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'x', null);
    $this->tracker->clear($this->server, 'disk:/', 1);

    $this->travel(3)->minutes();
    incidentTrackerSweep();

    Notification::assertSentToTimes($this->admin, HostIncidentResolved::class, 1);
});

test('a problem flapping within the notify delay pushes one opened and nothing else', function (): void {
    $first = $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'Disk / is 86% full', 'a');

    foreach (range(1, 3) as $flap) {
        $this->tracker->clear($this->server, 'disk:/', 1);
        $this->travel(5)->minutes();
        incidentTrackerSweep();
        $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'Disk / is 87% full', 'b');
        $this->travel(5)->minutes();
        incidentTrackerSweep();
    }

    expect(ServerIncident::query()->sole())
        ->id->toBe($first->id)
        ->status->toBe(IncidentStatus::Open)
        ->resolved_at->toBeNull()
        ->clear_streak->toBe(0)
        ->title->toBe('Disk / is 87% full')
        ->detail->toBe('b');
    Notification::assertSentToTimes($this->admin, HostIncidentOpened::class, 1);
    Notification::assertNotSentTo($this->admin, HostIncidentResolved::class);
    Notification::assertNotSentTo($this->admin, HostIncidentEscalated::class);
});

test('a recurrence after the resolved push went out opens a fresh incident and pushes opened', function (): void {
    $first = $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'x', null);
    $this->tracker->clear($this->server, 'disk:/', 1);
    $this->travel(10)->minutes();
    incidentTrackerSweep();

    $this->travel(1)->minutes();
    $second = $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'x', null);

    expect($second->id)->not->toBe($first->id)
        ->and(ServerIncident::query()->count())->toBe(2)
        ->and(ServerIncident::query()->open()->sole()->id)->toBe($second->id)
        ->and($first->fresh()->status)->toBe(IncidentStatus::Resolved);
    Notification::assertSentToTimes($this->admin, HostIncidentOpened::class, 2);
    Notification::assertSentToTimes($this->admin, HostIncidentResolved::class, 1);
});

test('a reopen within the notify delay pushes an escalation only when the severity rises above the one notified', function (): void {
    $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'w', null);
    $this->tracker->clear($this->server, 'disk:/', 1);
    $this->travel(5)->minutes();
    $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Critical, 'c', null);

    expect(ServerIncident::query()->sole())
        ->status->toBe(IncidentStatus::Open)
        ->severity->toBe(IncidentSeverity::Critical)
        ->notified_severity->toBe(IncidentSeverity::Critical);
    Notification::assertSentToTimes($this->admin, HostIncidentOpened::class, 1);
    Notification::assertSentToTimes($this->admin, HostIncidentEscalated::class, 1);

    $this->tracker->clear($this->server, 'disk:/', 1);
    $this->travel(5)->minutes();
    $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Critical, 'c', null);

    Notification::assertSentToTimes($this->admin, HostIncidentEscalated::class, 1);
    Notification::assertNotSentTo($this->admin, HostIncidentResolved::class);
});

test('the sweep skips an incident that reopened before its resolved push went out', function (): void {
    $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'x', null);
    $this->tracker->clear($this->server, 'disk:/', 1);
    $this->travel(10)->minutes();
    $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'x', null);

    expect($this->tracker->notifyHeldResolutions())->toBe(0);

    Notification::assertNotSentTo($this->admin, HostIncidentResolved::class);
    expect(ServerIncident::query()->sole()->status)->toBe(IncidentStatus::Open);
});

test('the held resolved push is scheduled every minute after the silent sweep', function (): void {
    $events = collect(resolve(Schedule::class)->events())->values();
    $silentIndex = $events->search(fn (ScheduleEvent $event): bool => str_contains((string) $event->command, 'servers:check-silent'));
    $notifyIndex = $events->search(fn (ScheduleEvent $event): bool => str_contains((string) $event->command, 'servers:notify-resolved'));

    expect($silentIndex)->toBeInt()
        ->and($notifyIndex)->toBeInt()
        ->and($silentIndex)->toBeLessThan($notifyIndex)
        ->and($events[$notifyIndex]->expression)->toBe('* * * * *')
        ->and($events[$notifyIndex]->withoutOverlapping)->toBeTrue();
});

test('the tracker locks the server row before it reads the incident', function (string $operation): void {
    if ($operation === 'clear') {
        $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'x', null);
    }

    DB::enableQueryLog();

    $operation === 'clear'
        ? $this->tracker->clear($this->server, 'disk:/')
        : $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'x', null);

    $queries = array_column(DB::getQueryLog(), 'query');
    $serverLock = array_find_key($queries, static fn (string $query): bool => str_contains($query, 'from "servers"') && str_contains($query, 'for update'));
    $incidentRead = array_find_key($queries, static fn (string $query): bool => str_contains($query, 'from "server_incidents"'));

    expect($serverLock)->toBeInt()
        ->and($incidentRead)->toBeInt()
        ->and($serverLock)->toBeLessThan($incidentRead);
})->with(['observe', 'clear']);

test('a notifier that throws is reported and neither the broadcast nor later incidents are lost', function (): void {
    Exceptions::fake();
    $this->mock(AdminNotifier::class, fn (MockInterface $mock) => $mock->shouldReceive('send')->twice()->andThrow(new RuntimeException('mail is down')));
    $incidentTracker = resolve(IncidentTracker::class);

    DB::transaction(function () use ($incidentTracker): void {
        $incidentTracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'Disk / is 86% full', null);
        $incidentTracker->observe($this->server, 'oom', IncidentSeverity::Warning, 'Out-of-memory kills', null);
    });

    Event::assertDispatchedTimes(ServerIncidentChanged::class, 2);
    Exceptions::assertReportedCount(2);
    Exceptions::assertReported(fn (RuntimeException $runtimeException): bool => $runtimeException->getMessage() === 'mail is down');
});

test('an observation whose transaction rolls back sends and broadcasts nothing', function (): void {
    try {
        DB::transaction(function (): void {
            $this->tracker->observe($this->server, 'disk:/', IncidentSeverity::Warning, 'Disk / is 86% full', null);

            throw new RuntimeException('later write failed');
        });
    } catch (RuntimeException) {
    }

    expect(ServerIncident::query()->exists())->toBeFalse();
    Notification::assertNothingSent();
    Event::assertNotDispatched(ServerIncidentChanged::class);
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
