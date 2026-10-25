<?php

declare(strict_types=1);

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Models\Server;
use App\Models\ServerIncident;
use App\Models\User;
use App\Notifications\HostIncidentOpened;
use App\Support\OpsHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AgentReportFixtures;

beforeEach(function (): void {
    $this->freezeTime();
    OpsHeartbeat::record(OpsHeartbeat::SCHEDULER);
    Notification::fake();
});

function silentRuleOpenSilentCount(): int
{
    return ServerIncident::query()->open()->where('key', 'silent')->count();
}

/**
 * Replays the platform from `$start` for `$seconds`: every minute the scheduler heartbeat and the silent sweep run,
 * and each server reports every minute from its offset in seconds on (null: it never reports again). A report and a
 * sweep at the same second run report first.
 *
 * @param  array<int, int|null>  $offsetsByServerId
 */
function silentRuleReplay(CarbonImmutable $start, array $offsetsByServerId, int $seconds): void
{
    $events = [];

    for ($second = 0; $second <= $seconds; $second += 60) {
        $events[] = [$second, 1, null];
    }

    foreach ($offsetsByServerId as $serverId => $offset) {
        for ($second = $offset; $second !== null && $second <= $seconds; $second += 60) {
            $events[] = [$second, 0, $serverId];
        }
    }

    usort($events, static fn (array $left, array $right): int => [$left[0], $left[1]] <=> [$right[0], $right[1]]);

    foreach ($events as [$second, $isSweep, $serverId]) {
        test()->travelTo($start->addSeconds($second));

        if ($isSweep === 0) {
            Server::query()->whereKey($serverId)->update(['last_report_at' => now()]);

            continue;
        }

        OpsHeartbeat::recordScheduler((int) config('mediamanager.monitoring.platform_gap_seconds'));
        test()->artisan('servers:check-silent')->assertSuccessful();
    }
}

/**
 * Three agents that last reported within 40 seconds of now, then an ingress outage of `$outageSeconds` during which
 * the scheduler keeps sweeping every minute.
 *
 * @return list<Server>
 */
function silentRuleIngressOutage(int $outageSeconds): array
{
    $servers = [
        Server::factory()->reporting()->create(['name' => 'babel', 'last_report_at' => now()]),
        Server::factory()->reporting()->create(['name' => 'turbo', 'last_report_at' => now()->subSeconds(20)]),
        Server::factory()->reporting()->create(['name' => 'mailserver', 'last_report_at' => now()->subSeconds(40)]),
    ];

    silentRuleReplay(now()->addSeconds(60), [], $outageSeconds - 60);

    return $servers;
}

test('an agent silent for more than three intervals opens a critical incident', function (): void {
    $server = Server::factory()->reporting()->create(['last_report_at' => now()->subSeconds(181)]);

    $this->artisan('servers:check-silent')->assertSuccessful();

    expect(ServerIncident::query()->open()->sole())
        ->server_id->toBe($server->id)
        ->key->toBe('silent')
        ->severity->toBe(IncidentSeverity::Critical)
        ->title->toBe('Agent silent')
        ->detail->toBe(sprintf('No report since %s (3 minutes ago).', now()->subSeconds(181)->toIso8601String()))
        ->context->toBe(['last_report_at' => now()->subSeconds(181)->toIso8601String()]);
});

test('three intervals or less is not silent', function (): void {
    Server::factory()->reporting()->create(['last_report_at' => now()->subSeconds(180)]);

    $this->artisan('servers:check-silent')->assertSuccessful();

    expect(ServerIncident::query()->count())->toBe(0);
});

test('a custom report interval scales the threshold', function (int $secondsAgo, int $expected): void {
    Server::factory()->reporting()->create([
        'report_interval_seconds' => 300,
        'last_report_at' => now()->subSeconds($secondsAgo),
    ]);

    $this->artisan('servers:check-silent')->assertSuccessful();

    expect(silentRuleOpenSilentCount())->toBe($expected);
})->with([
    'past three intervals' => [901, 1],
    'within three intervals' => [899, 0],
]);

test('an enrolled agent that never reported goes silent from its enrollment time', function (): void {
    Server::factory()->enrolled()->create(['enrolled_at' => now()->subMinutes(4)]);

    $this->artisan('servers:check-silent')->assertSuccessful();

    expect(ServerIncident::query()->open()->sole())
        ->key->toBe('silent')
        ->detail->toBe('No report since enrollment (4 minutes ago).')
        ->context->toBe(['last_report_at' => null]);
});

test('servers that never enrolled are skipped', function (): void {
    Server::factory()->pendingEnrollment()->create(['created_at' => now()->subDay()]);
    Server::factory()->create(['created_at' => now()->subDay()]);

    $this->artisan('servers:check-silent')->assertSuccessful();

    expect(ServerIncident::query()->count())->toBe(0);
});

test('after a platform outage the clock restarts from the resume', function (): void {
    Queue::fake();
    $server = Server::factory()->reporting()->create();
    $this->travel(2)->hours();

    $this->artisan('ops:record-heartbeat')->assertSuccessful();
    $this->artisan('servers:check-silent')->assertSuccessful();
    expect(silentRuleOpenSilentCount())->toBe(0);

    foreach ([3, 3, 3] as $minutes) {
        $this->travel($minutes)->minutes();
        $this->artisan('ops:record-heartbeat')->assertSuccessful();
        $this->artisan('servers:check-silent')->assertSuccessful();
        expect(silentRuleOpenSilentCount())->toBe(0);
    }

    $this->travel(1)->minutes();
    $this->artisan('ops:record-heartbeat')->assertSuccessful();
    $this->artisan('servers:check-silent')->assertSuccessful();
    expect(ServerIncident::query()->open()->sole())
        ->server_id->toBe($server->id)
        ->key->toBe('silent');
});

test('after a platform outage agents coming back at staggered times open no silent incidents', function (): void {
    $servers = Server::factory()->reporting()->count(3)->create();
    $this->travel(2)->hours();

    silentRuleReplay(now(), [$servers[0]->id => 0, $servers[1]->id => 120, $servers[2]->id => 290], 900);

    expect(ServerIncident::query()->exists())->toBeFalse();
});

test('after a platform outage an agent that never comes back pages once the backoff grace has passed', function (): void {
    $servers = Server::factory()->reporting()->count(3)->create();
    $this->travel(2)->hours();
    $resumedAt = now();

    silentRuleReplay($resumedAt, [$servers[0]->id => 0, $servers[1]->id => 120, $servers[2]->id => null], 540);

    expect(ServerIncident::query()->exists())->toBeFalse();

    silentRuleReplay($resumedAt->addSeconds(600), [$servers[0]->id => 0, $servers[1]->id => 0, $servers[2]->id => null], 0);

    expect(ServerIncident::query()->open()->sole()->server_id)->toBe($servers[2]->id);
});

test('after an ingress outage agents coming back at staggered times open no silent incidents', function (): void {
    [$babel, $turbo, $mailserver] = silentRuleIngressOutage(20 * 60);

    silentRuleReplay(now()->addSeconds(30), [$babel->id => 0, $turbo->id => 120, $mailserver->id => 290], 900);

    expect(ServerIncident::query()->exists())->toBeFalse();
});

test('after an ingress outage an agent that never comes back pages once the backoff grace has passed', function (): void {
    [$babel, $turbo, $mailserver] = silentRuleIngressOutage(20 * 60);
    $ingressBackAt = now()->addSeconds(30);

    silentRuleReplay($ingressBackAt, [$babel->id => 0, $turbo->id => 120, $mailserver->id => null], 570);

    expect(ServerIncident::query()->exists())->toBeFalse();

    silentRuleReplay($ingressBackAt->addSeconds(630), [$babel->id => 0, $turbo->id => 0, $mailserver->id => null], 0);

    expect(ServerIncident::query()->open()->sole()->server_id)->toBe($mailserver->id);
});

test('an ingress outage shorter than the silence threshold opens nothing when agents return on backoff', function (): void {
    [$babel, $turbo, $mailserver] = silentRuleIngressOutage(150);

    silentRuleReplay(now()->addSeconds(5), [$babel->id => 0, $turbo->id => 160, $mailserver->id => 145], 900);

    expect(ServerIncident::query()->exists())->toBeFalse();
});

test('the sweep does nothing while the scheduler heartbeat is stale', function (): void {
    Server::factory()->reporting()->create(['last_report_at' => now()->subHour()]);
    $this->travel(10)->minutes();

    $this->artisan('servers:check-silent')->assertSuccessful();

    expect(ServerIncident::query()->count())->toBe(0);
});

test('when every reporting agent went silent within two minutes nothing opens and a warning is logged', function (): void {
    Log::spy();
    Server::factory()->reporting()->create(['last_report_at' => now()->subMinutes(10)]);
    Server::factory()->reporting()->create(['last_report_at' => now()->subSeconds(630)]);
    Server::factory()->reporting()->create(['last_report_at' => now()->subMinutes(11)]);

    $this->artisan('servers:check-silent')->assertSuccessful();

    expect(ServerIncident::query()->count())->toBe(0);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'every agent went silent at once'))
        ->once();
});

test('agents that stop together are suppressed although only one has crossed the threshold yet', function (): void {
    Log::spy();
    Server::factory()->reporting()->create(['last_report_at' => now()->subSeconds(190)]);
    Server::factory()->reporting()->create(['last_report_at' => now()->subSeconds(160)]);

    $this->artisan('servers:check-silent')->assertSuccessful();

    expect(ServerIncident::query()->count())->toBe(0);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'every agent went silent at once'))
        ->once();
});

test('a server that went silent long before does not defeat the ingress suppression', function (int $deadSecondsAgo, bool $silentIncidentOpen): void {
    $dead = Server::factory()->reporting()->create(['last_report_at' => now()->subSeconds($deadSecondsAgo)]);

    if ($silentIncidentOpen) {
        ServerIncident::factory()->for($dead)->key('silent')->critical()->open()->create();
    }

    Server::factory()->reporting()->create(['last_report_at' => now()->subMinutes(10)]);
    Server::factory()->reporting()->create(['last_report_at' => now()->subMinutes(10)->addSeconds(60)]);

    $this->artisan('servers:check-silent')->assertSuccessful();

    expect(ServerIncident::query()->count())->toBe($silentIncidentOpen ? 1 : 0);
})->with([
    'dead for two days' => [2 * 86_400, false],
    'already has an open silent incident' => [700, true],
]);

test('two agents just past the threshold alert while a third reported recently', function (): void {
    Server::factory()->reporting()->create(['name' => 'turbo', 'last_report_at' => now()->subSeconds(181)]);
    Server::factory()->reporting()->create(['name' => 'mailserver', 'last_report_at' => now()->subSeconds(181)]);
    Server::factory()->reporting()->create(['name' => 'babel', 'last_report_at' => now()->subSeconds(70)]);

    $this->artisan('servers:check-silent')->assertSuccessful();

    expect(ServerIncident::query()->open()->where('key', 'silent')->where('severity', IncidentSeverity::Critical)->count())->toBe(2);
});

test('a single-server fleet that goes silent still alerts', function (): void {
    Log::spy();
    Server::factory()->reporting()->create(['last_report_at' => now()->subMinutes(10)]);

    $this->artisan('servers:check-silent')->assertSuccessful();

    expect(silentRuleOpenSilentCount())->toBe(1);
    Log::shouldNotHaveReceived('warning');
});

test('the ingress warning is logged once per suppression episode', function (): void {
    Log::spy();
    $servers = Server::factory()->reporting()->count(2)->create(['last_report_at' => now()->subMinutes(5)]);

    $this->artisan('servers:check-silent')->assertSuccessful();
    $this->travel(1)->minutes();
    OpsHeartbeat::record(OpsHeartbeat::SCHEDULER);
    $this->artisan('servers:check-silent')->assertSuccessful();

    $servers->each(fn (Server $server): bool => $server->update(['last_report_at' => now()]));
    $this->artisan('servers:check-silent')->assertSuccessful();

    $this->travel(5)->minutes();
    OpsHeartbeat::record(OpsHeartbeat::SCHEDULER);
    $this->artisan('servers:check-silent')->assertSuccessful();

    expect(ServerIncident::query()->count())->toBe(0);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'every agent went silent at once'))
        ->twice();
});

test('every agent silent but at times more than two minutes apart still alerts', function (): void {
    Server::factory()->reporting()->create(['last_report_at' => now()->subMinutes(5)]);
    Server::factory()->reporting()->create(['last_report_at' => now()->subMinutes(20)]);

    $this->artisan('servers:check-silent')->assertSuccessful();

    expect(silentRuleOpenSilentCount())->toBe(2);
});

test('two agents silent while a third keeps reporting open two critical incidents', function (): void {
    $turbo = Server::factory()->reporting()->create(['name' => 'turbo', 'last_report_at' => now()->subMinutes(10)]);
    $mailserver = Server::factory()->reporting()->create(['name' => 'mailserver', 'last_report_at' => now()->subMinutes(10)->addSeconds(20)]);
    Server::factory()->reporting()->create(['name' => 'babel', 'last_report_at' => now()->subSeconds(30)]);

    $this->artisan('servers:check-silent')->assertSuccessful();

    $incidents = ServerIncident::query()->open()->where('key', 'silent')->where('severity', IncidentSeverity::Critical)->get();
    expect($incidents)->toHaveCount(2)
        ->and($incidents->pluck('server_id')->sort()->values()->all())->toBe(collect([$turbo->id, $mailserver->id])->sort()->values()->all());
});

test('one silent agent among reporting ones still opens', function (): void {
    $silent = Server::factory()->reporting()->create(['last_report_at' => now()->subMinutes(5)]);
    Server::factory()->reporting()->create(['last_report_at' => now()->subSeconds(20)]);

    $this->artisan('servers:check-silent')->assertSuccessful();

    expect(ServerIncident::query()->open()->sole()->server_id)->toBe($silent->id);
});

test('a repeat sweep refreshes the detail without a second notification', function (): void {
    $admin = User::factory()->admin()->create();
    Server::factory()->reporting()->create(['last_report_at' => now()->subMinutes(4)]);

    $this->artisan('servers:check-silent')->assertSuccessful();
    $this->travel(1)->minutes();
    OpsHeartbeat::record(OpsHeartbeat::SCHEDULER);
    $this->artisan('servers:check-silent')->assertSuccessful();

    expect(ServerIncident::query()->sole())
        ->detail->toContain('(5 minutes ago)')
        ->and(ServerIncident::query()->sole()->last_seen_at->toIso8601String())->toBe(now()->toIso8601String());
    Notification::assertSentToTimes($admin, HostIncidentOpened::class, 1);
});

test('the next report resolves the silent incident', function (): void {
    $server = Server::factory()->reporting()->create(['last_report_at' => now()->subMinutes(5)]);
    $this->artisan('servers:check-silent')->assertSuccessful();
    expect(silentRuleOpenSilentCount())->toBe(1);

    AgentReportFixtures::dispatchReport($server, AgentReportFixtures::agentReportBody());

    expect(ServerIncident::query()->where('key', 'silent')->sole())
        ->status->toBe(IncidentStatus::Resolved)
        ->resolved_at->not->toBeNull();
});

test('the silent sweep is scheduled every minute after the heartbeat', function (): void {
    $events = collect(resolve(Schedule::class)->events())->values();
    $heartbeatIndex = $events->search(fn (Event $event): bool => str_contains((string) $event->command, 'ops:record-heartbeat'));
    $sweepIndex = $events->search(fn (Event $event): bool => str_contains((string) $event->command, 'servers:check-silent'));

    expect($heartbeatIndex)->toBeInt()
        ->and($sweepIndex)->toBeInt()
        ->and($heartbeatIndex)->toBeLessThan($sweepIndex)
        ->and($events[$sweepIndex]->expression)->toBe('* * * * *')
        ->and($events[$sweepIndex]->withoutOverlapping)->toBeTrue();
});
