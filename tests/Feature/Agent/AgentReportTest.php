<?php

declare(strict_types=1);

use App\Enums\QueueLane;
use App\Jobs\ProcessAgentReport;
use App\Models\Server;
use App\Models\ServerCheck;
use App\Services\HostMonitoring\ReportRejection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\MessageBag;
use Illuminate\Testing\TestResponse;
use Tests\Support\AgentReportFixtures;

beforeEach(function (): void {
    Queue::fake();
});

/**
 * Posts a report the way the Go agent does: a JSON body without an `Accept: application/json` header.
 *
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 */
function agentReportTestPost(array $body, array $headers): TestResponse
{
    return test()->json('POST', route('agent.report'), $body, ['Accept' => '*/*', ...$headers]);
}

test('a report updates the server snapshot and queues processing', function (): void {
    $this->freezeSecond();
    $server = Server::factory()->enrolled()->create();

    $this->postJson(route('agent.report'), AgentReportFixtures::agentReportBody(), AgentReportFixtures::agentBearer($server))
        ->assertOk()
        ->assertJsonPath('config_version', 1)
        ->assertJsonPath('config', null)
        ->assertJsonPath('report_interval_seconds', 60);

    $server->refresh();

    expect($server->agent_version)->toBe('0.1.0')
        ->and($server->hostname)->toBe('babel')
        ->and($server->os)->toBe('unraid 7.1.4')
        ->and($server->kernel)->toBe('6.12.24-Unraid')
        ->and($server->arch)->toBe('amd64')
        ->and($server->last_report_at?->equalTo(now()))->toBeTrue()
        ->and($server->last_report['received_at'])->toBe(now()->toIso8601String())
        ->and($server->last_report['uptime_seconds'])->toBe(86_400)
        ->and($server->last_report['cpu_percent'])->toBe(12.5)
        ->and($server->last_report['load'])->toBe([0.5, 0.7, 0.9])
        ->and($server->last_report['memory']['available_bytes'])->toBe(6_442_450_944)
        ->and($server->last_report['filesystems'][0]['mount'])->toBe('/')
        ->and($server->last_report['filesystems'][0]['unresponsive'])->toBeFalse()
        ->and($server->last_report['failed_units'])->toBe(['backup.service'])
        ->and($server->last_report['containers'][0]['name'])->toBe('postfix-mailcow');

    Queue::assertPushedOn(QueueLane::Webhooks->value, ProcessAgentReport::class, fn (ProcessAgentReport $processAgentReport): bool => $processAgentReport->serverId === $server->id
        && $processAgentReport->receivedAt === now()->toIso8601String());
});

test('a report from an agent with the current config gets config null', function (): void {
    $server = Server::factory()->enrolled()->create();
    ServerCheck::factory()->for($server)->create();

    $this->postJson(route('agent.report'), AgentReportFixtures::agentReportBody(['config_version' => 2]), AgentReportFixtures::agentBearer($server))
        ->assertOk()
        ->assertJsonPath('config_version', 2)
        ->assertJsonPath('config', null);
});

test('a report from an agent with an older config gets the enabled checks', function (): void {
    $server = Server::factory()->enrolled()->create();
    $enabled = ServerCheck::factory()->for($server)->mountPresent()->create();
    ServerCheck::factory()->for($server)->fileFreshness()->disabled()->create();

    $response = $this->postJson(route('agent.report'), AgentReportFixtures::agentReportBody(), AgentReportFixtures::agentBearer($server))
        ->assertOk()
        ->assertJsonPath('config_version', 3);

    expect($response->json('config.checks'))->toBe([[
        'id' => $enabled->uuid,
        'type' => 'mount_present',
        'params' => ['path' => '/mnt/backups', 'fstype' => 'nfs4', 'source' => 'nas:/backups'],
    ]]);
});

test('a first report with config_version 0 receives the config', function (): void {
    $server = Server::factory()->enrolled()->create();
    $check = ServerCheck::factory()->for($server)->create();

    $response = $this->postJson(route('agent.report'), AgentReportFixtures::agentReportBody(['config_version' => 0]), AgentReportFixtures::agentBearer($server))
        ->assertOk();

    expect($response->json('config.checks.*.id'))->toBe([$check->uuid]);
});

test('unknown fields anywhere in the report are ignored and not queued', function (): void {
    $server = Server::factory()->enrolled()->create();

    $this->postJson(route('agent.report'), AgentReportFixtures::agentReportBody([
        'future' => ['anything' => true],
        'host.future' => 'x',
        'metrics.future' => 1,
        'metrics.memory.future' => 1,
        'metrics.filesystems.0.future' => 'x',
        'metrics.containers.0.future' => 'x',
        'checks.0.future' => 'x',
    ]), AgentReportFixtures::agentBearer($server))->assertOk();

    Queue::assertPushed(ProcessAgentReport::class, fn (ProcessAgentReport $processAgentReport): bool => ! array_key_exists('future', $processAgentReport->payload)
        && ! array_key_exists('future', $processAgentReport->payload['host'])
        && ! array_key_exists('future', $processAgentReport->payload['metrics'])
        && ! array_key_exists('future', $processAgentReport->payload['metrics']['memory'])
        && ! array_key_exists('future', $processAgentReport->payload['metrics']['filesystems'][0])
        && ! array_key_exists('future', $processAgentReport->payload['metrics']['containers'][0])
        && ! array_key_exists('future', $processAgentReport->payload['checks'][0]));
});

test('a report without the bearer token answers 401 json', function (): void {
    $testResponse = agentReportTestPost(AgentReportFixtures::agentReportBody(), [])
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/json');

    expect($testResponse->json('message'))->toBeString();
    Queue::assertNothingPushed();
});

test('a negative byte count answers 422 json', function (): void {
    $server = Server::factory()->enrolled()->create();

    $testResponse = agentReportTestPost(
        AgentReportFixtures::agentReportBody(['metrics.filesystems.0.used_bytes' => -1]),
        AgentReportFixtures::agentBearer($server),
    )->assertUnprocessable()->assertHeader('Content-Type', 'application/json');

    expect($testResponse->json('errors'))->toHaveKey('metrics.filesystems.0.used_bytes');
    Queue::assertNothingPushed();
});

test('Unraid nulls for failed units and containers are accepted', function (): void {
    $server = Server::factory()->enrolled()->create();

    $this->postJson(route('agent.report'), AgentReportFixtures::agentReportBody([
        'metrics.failed_units' => null,
        'metrics.containers' => null,
    ]), AgentReportFixtures::agentBearer($server))->assertOk();

    expect($server->fresh()->last_report)
        ->failed_units->toBeNull()
        ->containers->toBeNull();
});

test('a container without a healthcheck reports a null health', function (): void {
    $server = Server::factory()->enrolled()->create();

    $this->postJson(route('agent.report'), AgentReportFixtures::agentReportBody([
        'metrics.containers' => [['name' => 'plex', 'state' => 'running', 'health' => null, 'restart_count' => 0]],
    ]), AgentReportFixtures::agentBearer($server))->assertOk();

    expect($server->fresh()->last_report['containers'][0])
        ->name->toBe('plex')
        ->state->toBe('running')
        ->health->toBeNull()
        ->restart_count->toBe(0);
});

test('an empty check list is accepted', function (): void {
    $server = Server::factory()->enrolled()->create();

    $this->postJson(route('agent.report'), AgentReportFixtures::agentReportBody(['checks' => []]), AgentReportFixtures::agentBearer($server))
        ->assertOk();

    Queue::assertPushed(ProcessAgentReport::class, fn (ProcessAgentReport $processAgentReport): bool => ($processAgentReport->payload['checks'] ?? null) === []);
});

test('an unresponsive filesystem without byte fields is accepted', function (): void {
    $server = Server::factory()->enrolled()->create();

    $this->postJson(route('agent.report'), AgentReportFixtures::agentReportBody([
        'metrics.filesystems' => [AgentReportFixtures::agentFilesystem('/'), AgentReportFixtures::agentFilesystem('/mnt/backups', unresponsive: true)],
    ]), AgentReportFixtures::agentBearer($server))->assertOk();

    expect($server->fresh()->last_report['filesystems'][1])
        ->mount->toBe('/mnt/backups')
        ->device->toBe('nas:/backups')
        ->fstype->toBe('nfs4')
        ->unresponsive->toBeTrue()
        ->total_bytes->toBeNull()
        ->used_bytes->toBeNull()
        ->available_bytes->toBeNull();
});

test('a responsive filesystem missing its byte fields answers 422', function (): void {
    $server = Server::factory()->enrolled()->create();
    $filesystem = AgentReportFixtures::agentFilesystem('/mnt/backups');
    unset($filesystem['used_bytes']);

    $response = $this->postJson(route('agent.report'), AgentReportFixtures::agentReportBody(['metrics.filesystems' => [$filesystem]]), AgentReportFixtures::agentBearer($server))
        ->assertUnprocessable();

    expect($response->json('errors'))->toHaveKey('metrics.filesystems.0.used_bytes');
});

test('a filesystem from an agent that predates the unresponsive flag counts as responsive', function (): void {
    $server = Server::factory()->enrolled()->create();
    $filesystem = AgentReportFixtures::agentFilesystem('/');
    unset($filesystem['unresponsive']);

    $this->postJson(route('agent.report'), AgentReportFixtures::agentReportBody(['metrics.filesystems' => [$filesystem]]), AgentReportFixtures::agentBearer($server))
        ->assertOk();

    expect($server->fresh()->last_report['filesystems'][0])
        ->unresponsive->toBeFalse()
        ->used_bytes->toBe(32_212_254_720);
});

test('a report body over 512 KB answers 413 before authentication', function (): void {
    agentReportTestPost(AgentReportFixtures::agentReportBody(['blob' => str_repeat('a', 513 * 1024)]), ['Authorization' => 'Bearer bogus'])
        ->assertStatus(413);

    Queue::assertNothingPushed();
});

test('reports are limited per server on the report route', function (): void {
    config()->set('mediamanager.monitoring.report_rate_limit_per_minute', 1);
    $serverA = Server::factory()->enrolled()->create();
    $serverB = Server::factory()->enrolled()->create();

    $this->postJson(route('agent.report'), AgentReportFixtures::agentReportBody(), AgentReportFixtures::agentBearer($serverA))->assertOk();
    $this->postJson(route('agent.report'), AgentReportFixtures::agentReportBody(), AgentReportFixtures::agentBearer($serverA))
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');
    $this->postJson(route('agent.report'), AgentReportFixtures::agentReportBody(), AgentReportFixtures::agentBearer($serverB))->assertOk();
});

test('a rejected report records a summary of why on the server without the payload', function (): void {
    $this->freezeSecond();
    $server = Server::factory()->reporting()->create();
    $lastReportAt = $server->last_report_at;

    agentReportTestPost(
        AgentReportFixtures::agentReportBody([
            'metrics.filesystems.0.used_bytes' => -1,
            'metrics.cpu_percent' => 'secret-cpu-value',
            'host.hostname' => null,
        ]),
        AgentReportFixtures::agentBearer($server),
    )->assertUnprocessable();

    $server->refresh();

    expect($server->last_report_error_at?->equalTo(now()))->toBeTrue()
        ->and($server->last_report_error)->toStartWith('The host.hostname field is required.')
        ->and($server->last_report_error)->toEndWith('(and 2 more errors)')
        ->and($server->last_report_error)->not->toContain('secret-cpu-value')
        ->and($server->last_report_at?->equalTo($lastReportAt))->toBeTrue();
});

test('a rejection summary is cut to fit its column', function (): void {
    $summary = ReportRejection::summary(new MessageBag(['schema' => [str_repeat('a', 1000)], 'host' => ['b']]));

    expect(mb_strlen($summary))->toBe(500)
        ->and($summary)->toEndWith('… (and 1 more error)');
});

test('an accepted report keeps the last rejection for the server page', function (): void {
    $server = Server::factory()->enrolled()->create(['last_report_error' => 'The schema field is required.', 'last_report_error_at' => now()->subMinute()]);

    agentReportTestPost(AgentReportFixtures::agentReportBody(), AgentReportFixtures::agentBearer($server))->assertOk();

    expect($server->fresh()->last_report_error)->toBe('The schema field is required.');
});
