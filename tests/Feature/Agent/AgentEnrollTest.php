<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\HostMonitoring\ServerEnrollment;
use Database\Factories\ServerFactory;
use Tests\Support\AgentReportFixtures;

test('a valid enrollment token returns an agent token once', function (): void {
    $server = Server::factory()->create();
    $token = resolve(ServerEnrollment::class)->issueToken($server);

    $response = $this->postJson(route('agent.enroll'), AgentReportFixtures::agentEnrollBody($token))->assertCreated();

    [$uuid, $secret] = explode('.', (string) $response->json('agent_token'), 2);
    $server->refresh();

    expect($token)->toHaveLength(32)
        ->and($response->json('server_uuid'))->toBe($server->uuid)
        ->and($uuid)->toBe($server->uuid)
        ->and($secret)->toHaveLength(48)
        ->and($response->json('report_interval_seconds'))->toBe(60)
        ->and($server->token_hash)->toBe(hash('sha256', $secret))
        ->and($server->hostname)->toBe('babel')
        ->and($server->os)->toBe('Debian GNU/Linux 12 (bookworm)')
        ->and($server->kernel)->toBe('6.1.0-18-amd64')
        ->and($server->arch)->toBe('amd64')
        ->and($server->agent_version)->toBe('0.1.0')
        ->and($server->enrolled_at)->not->toBeNull()
        ->and($server->enrollment_expires_at)->toBeNull();
});

test('a server created with the factory token can enroll', function (): void {
    $server = Server::factory()->pendingEnrollment()->create();

    $this->postJson(route('agent.enroll'), AgentReportFixtures::agentEnrollBody(ServerFactory::ENROLLMENT_TOKEN))->assertCreated();

    expect($server->fresh()->isEnrolled())->toBeTrue();
});

test('replaying a used enrollment token answers 410', function (): void {
    $server = Server::factory()->create();
    $token = resolve(ServerEnrollment::class)->issueToken($server);
    $this->postJson(route('agent.enroll'), AgentReportFixtures::agentEnrollBody($token))->assertCreated();
    $tokenHash = $server->fresh()->token_hash;

    $this->postJson(route('agent.enroll'), AgentReportFixtures::agentEnrollBody($token))->assertStatus(410);

    expect($server->fresh()->token_hash)->toBe($tokenHash);
});

test('an expired enrollment token answers 410', function (): void {
    $server = Server::factory()->create();
    $token = resolve(ServerEnrollment::class)->issueToken($server);

    $this->travel(25)->hours();

    $this->postJson(route('agent.enroll'), AgentReportFixtures::agentEnrollBody($token))->assertStatus(410);

    expect($server->fresh()->isEnrolled())->toBeFalse();
});

test('an unknown enrollment token answers 404 as json', function (): void {
    $this->postJson(route('agent.enroll'), AgentReportFixtures::agentEnrollBody(str_repeat('x', 32)))
        ->assertNotFound()
        ->assertJsonPath('message', fn ($message): bool => is_string($message));
});

test('a validation failure answers 422 json even without an Accept header', function (): void {
    $this->call('POST', route('agent.enroll'), [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['hostname' => 'x']))
        ->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/json');
});

test('an enrollment token that is not 32 alphanumeric characters is rejected', function (string $token): void {
    $this->postJson(route('agent.enroll'), AgentReportFixtures::agentEnrollBody($token))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['enrollment_token']);
})->with([
    'too short' => 'abc',
    'hyphens' => 'enroll-token-000000000000000000a',
]);

test('enrollment is limited to 10 attempts a minute per ip', function (): void {
    foreach (range(1, 10) as $attempt) {
        $this->postJson(route('agent.enroll'), AgentReportFixtures::agentEnrollBody(str_repeat('x', 32)))->assertNotFound();
    }

    $this->postJson(route('agent.enroll'), AgentReportFixtures::agentEnrollBody(str_repeat('x', 32)))
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');
});

test('an oversized enrollment body answers 413', function (): void {
    $this->postJson(route('agent.enroll'), AgentReportFixtures::agentEnrollBody(str_repeat('x', 32), ['hostname' => str_repeat('a', 513 * 1024)]))
        ->assertStatus(413);
});

test('re-enrolling with a regenerated token replaces the old agent token', function (): void {
    $server = Server::factory()->create();
    $serverEnrollment = resolve(ServerEnrollment::class);

    $first = $this->postJson(route('agent.enroll'), AgentReportFixtures::agentEnrollBody($serverEnrollment->issueToken($server)))->assertCreated();
    $firstHash = $server->fresh()->token_hash;

    $second = $this->postJson(route('agent.enroll'), AgentReportFixtures::agentEnrollBody($serverEnrollment->issueToken($server->fresh())))->assertCreated();

    expect($server->fresh()->token_hash)->not->toBe($firstHash)
        ->and($second->json('agent_token'))->not->toBe($first->json('agent_token'));
});
