<?php

declare(strict_types=1);

use App\Enums\HostCheckStatus;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\ServerCheck;
use App\Models\ServerIncident;
use Database\Factories\ServerFactory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

test('a new server gets a uuid and keeps an integer key', function (): void {
    $server = Server::factory()->create();

    expect($server->id)->toBeInt()->and(Str::isUuid($server->uuid))->toBeTrue();
});

test('token hashes and rule state never serialize', function (): void {
    expect(Server::factory()->enrolled()->create()->toArray())
        ->not->toHaveKeys(['token_hash', 'enrollment_token_hash', 'rule_state']);
});

test('the pending enrollment factory token is a 32 character alphanumeric value', function (): void {
    expect(ServerFactory::ENROLLMENT_TOKEN)->toMatch('/^[A-Za-z0-9]{32}$/');

    $server = Server::factory()->pendingEnrollment()->create();

    expect($server->enrollment_token_hash)->toBe(hash('sha256', ServerFactory::ENROLLMENT_TOKEN))
        ->and($server->enrollmentIsUsable())->toBeTrue()
        ->and($server->isEnrolled())->toBeFalse();
});

test('an enrolled server is no longer enrollable', function (): void {
    $server = Server::factory()->enrolled()->create();

    expect($server->isEnrolled())->toBeTrue()
        ->and($server->token_hash)->toBe(hash('sha256', 'secret'))
        ->and($server->enrollmentIsUsable())->toBeFalse();
});

test('creating, reconfiguring, toggling and deleting a check bumps the server config version', function (): void {
    $server = Server::factory()->create();
    $check = ServerCheck::factory()->mountPresent()->for($server)->create();
    expect($server->fresh()->config_version)->toBe(2);

    $check->update(['params' => ['path' => '/mnt/other']]);
    $check->update(['is_enabled' => false]);
    $check->delete();

    expect($server->fresh()->config_version)->toBe(5);
});

test('recording a check result does not bump the config version', function (): void {
    $check = ServerCheck::factory()->mountPresent()->create();
    $before = $check->server->fresh()->config_version;

    $check->update(['last_status' => HostCheckStatus::Crit, 'last_message' => 'x', 'last_evaluated_at' => now()]);

    expect($check->server->fresh()->config_version)->toBe($before);
});

test('a check labels itself and keys its incident by uuid', function (): void {
    $check = ServerCheck::factory()->mountPresent()->create();

    expect($check->label())->toBe(sprintf('Mount present: %s', $check->params['path']))
        ->and($check->incidentKey())->toBe(sprintf('check:%s', $check->uuid))
        ->and($check->type->subject($check->params))->toBe($check->params['path']);
});

test('only one open incident per server and key can exist', function (): void {
    $server = Server::factory()->create();
    ServerIncident::factory()->for($server)->open()->key('disk:/')->create();
    ServerIncident::factory()->for($server)->resolved()->key('disk:/')->create();

    expect(fn () => ServerIncident::factory()->for($server)->open()->key('disk:/')->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

test('an incident key longer than 255 characters is stored in full', function (): void {
    $key = sprintf('disk:/%s', str_repeat('a', 1000));
    $incident = ServerIncident::factory()->open()->key($key)->create();

    expect($incident->fresh()->key)->toBe($key);
});

test('the open scope returns only open incidents', function (): void {
    $server = Server::factory()->create();
    $open = ServerIncident::factory()->for($server)->open()->key('oom')->create();
    ServerIncident::factory()->for($server)->resolved()->key('oom')->create();

    expect(ServerIncident::query()->open()->pluck('id')->all())->toBe([$open->id]);
});

test('status reads pending, ok, incident and silent', function (): void {
    $pending = Server::factory()->create(['name' => 'pending']);
    $neverReported = Server::factory()->enrolled()->create(['name' => 'never-reported']);
    $ok = Server::factory()->reporting()->create(['name' => 'ok']);
    $incident = Server::factory()->reporting()->create(['name' => 'incident']);
    $silent = Server::factory()->reporting()->create(['name' => 'silent']);
    $resolvedOnly = Server::factory()->reporting()->create(['name' => 'resolved-only']);

    ServerIncident::factory()->for($incident)->open()->key('disk:/')->create();
    ServerIncident::factory()->for($silent)->open()->key('silent')->create();
    ServerIncident::factory()->for($silent)->open()->key('disk:/')->create();
    ServerIncident::factory()->for($resolvedOnly)->resolved()->key('disk:/')->create();

    $statuses = Server::query()->withStatus()->get()->mapWithKeys(fn (Server $server): array => [$server->name => $server->status()]);

    expect($statuses->all())->toBe([
        'pending' => ServerStatus::Pending,
        'never-reported' => ServerStatus::Pending,
        'ok' => ServerStatus::Ok,
        'incident' => ServerStatus::Incident,
        'silent' => ServerStatus::Silent,
        'resolved-only' => ServerStatus::Ok,
    ]);
});
