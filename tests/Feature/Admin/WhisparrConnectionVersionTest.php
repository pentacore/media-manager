<?php

declare(strict_types=1);

use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Enums\WhisparrVersion;
use App\Models\ServiceConnection;
use App\Models\User;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
});

test('storing a Whisparr connection persists the version into settings', function (): void {
    $this->post(route('admin.connections.store'), [
        'type' => 'whisparr',
        'name' => 'My Whisparr',
        'url' => 'http://whisparr.local:6969',
        'api_key' => 'secret',
        'webhook_token' => str_repeat('a', 12),
        'whisparr_version' => 'v2',
    ])->assertRedirect();

    $connection = ServiceConnection::where('type', ServiceType::Whisparr)->firstOrFail();
    expect($connection->settings['whisparr_version'] ?? null)->toBe('v2');
});

test('updating a Whisparr connection changes the stored version', function (): void {
    $connection = ServiceConnection::factory()->whisparr()->whisparrVersion(WhisparrVersion::V2)->create();

    $this->put(route('admin.connections.update', $connection), [
        'type' => 'whisparr',
        'name' => $connection->name,
        'url' => $connection->url,
        'whisparr_version' => 'v3',
    ])->assertRedirect();

    expect($connection->fresh()->settings['whisparr_version'] ?? null)->toBe('v3');
});

test('an invalid whisparr_version is rejected', function (): void {
    $this->post(route('admin.connections.store'), [
        'type' => 'whisparr',
        'name' => 'My Whisparr',
        'url' => 'http://whisparr.local:6969',
        'api_key' => 'secret',
        'webhook_token' => str_repeat('a', 12),
        'whisparr_version' => 'v9',
    ])->assertSessionHasErrors('whisparr_version');
});
