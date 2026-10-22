<?php

declare(strict_types=1);

use App\Models\Server;
use Illuminate\Support\Facades\Route;
use Tests\Support\AgentReportFixtures;

beforeEach(function (): void {
    Route::post('api/agent/v1/_probe', fn () => response()->json(['ok' => true]))
        ->middleware(['webhook.payload-limit:mediamanager.monitoring.max_payload_kb', 'agent.token', 'throttle:agent']);
});

test('a correct bearer token passes', function (): void {
    $server = Server::factory()->enrolled()->create();

    $this->postJson('/api/agent/v1/_probe', [], AgentReportFixtures::agentBearer($server))
        ->assertOk()
        ->assertJsonPath('ok', true);
});

test('a wrong secret, an unknown uuid, a malformed token and no token answer 401', function (string $case): void {
    $server = Server::factory()->enrolled()->create();

    $headers = match ($case) {
        'wrong secret' => AgentReportFixtures::agentBearer($server, 'nope'),
        'unknown uuid' => ['Authorization' => 'Bearer 0198a3c2-0000-7000-8000-000000000000.secret'],
        'no dot' => ['Authorization' => 'Bearer '.$server->uuid],
        'not a uuid' => ['Authorization' => 'Bearer not-a-uuid.secret'],
        'empty' => ['Authorization' => 'Bearer '],
        'no header' => [],
    };

    $this->postJson('/api/agent/v1/_probe', [], $headers)->assertUnauthorized();
})->with(['wrong secret', 'unknown uuid', 'no dot', 'not a uuid', 'empty', 'no header']);

test('a server that has not finished enrolling cannot authenticate', function (): void {
    $server = Server::factory()->pendingEnrollment()->create();

    $this->postJson('/api/agent/v1/_probe', [], AgentReportFixtures::agentBearer($server, 'secret'))->assertUnauthorized();
});

test('a deleted server can no longer authenticate', function (): void {
    $server = Server::factory()->enrolled()->create();
    $headers = AgentReportFixtures::agentBearer($server);
    $server->delete();

    $this->postJson('/api/agent/v1/_probe', [], $headers)->assertUnauthorized();
});

test('a body over 512 KB answers 413 before authentication', function (): void {
    $this->postJson('/api/agent/v1/_probe', ['blob' => str_repeat('a', 513 * 1024)], ['Authorization' => 'Bearer bogus'])
        ->assertStatus(413);
});

test('reports are limited per server, not per ip', function (): void {
    config()->set('mediamanager.monitoring.report_rate_limit_per_minute', 2);
    $serverA = Server::factory()->enrolled()->create();
    $serverB = Server::factory()->enrolled()->create();

    foreach ([1, 2] as $report) {
        $this->postJson('/api/agent/v1/_probe', [], AgentReportFixtures::agentBearer($serverA))->assertOk();
    }

    $this->postJson('/api/agent/v1/_probe', [], AgentReportFixtures::agentBearer($serverA))
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');
    $this->postJson('/api/agent/v1/_probe', [], AgentReportFixtures::agentBearer($serverB))->assertOk();
});

test('reports are limited per server on a route inside the api middleware group', function (): void {
    config()->set('mediamanager.monitoring.report_rate_limit_per_minute', 1);
    Route::middleware('api')->post('api/agent/v1/_grouped-probe', fn () => response()->json(['ok' => true]))
        ->middleware(['agent.token', 'throttle:agent']);
    $serverA = Server::factory()->enrolled()->create();
    $serverB = Server::factory()->enrolled()->create();

    $this->postJson('/api/agent/v1/_grouped-probe', [], AgentReportFixtures::agentBearer($serverA))->assertOk();
    $this->postJson('/api/agent/v1/_grouped-probe', [], AgentReportFixtures::agentBearer($serverA))->assertTooManyRequests();
    $this->postJson('/api/agent/v1/_grouped-probe', [], AgentReportFixtures::agentBearer($serverB))->assertOk();
});
