<?php

use App\Jobs\FetchLatestServiceVersion;
use App\Jobs\PingServiceHealth;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('guests cannot access service connections', function (): void {
    $this->get(route('admin.connections.index'))
        ->assertRedirect(route('login'));
});

test('non-admin users cannot access service connections', function (): void {
    $user = User::factory()->member()->create();

    $this->actingAs($user)
        ->get(route('admin.connections.index'))
        ->assertForbidden();
});

test('admin can list service connections', function (): void {
    $admin = User::factory()->admin()->create();
    ServiceConnection::factory()->sonarr()->create();
    ServiceConnection::factory()->radarr()->create();

    $this->actingAs($admin)
        ->get(route('admin.connections.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Connections/Index')
            ->has('connections', 2)
        );
});

test('admin can view create form', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.connections.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Connections/Create')
            ->has('serviceTypes')
        );
});

test('admin can store a service connection', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.connections.store'), [
            'type' => 'sonarr',
            'name' => 'My Sonarr',
            'url' => 'http://sonarr.local:8989',
            'api_key' => 'abc123def456',
            'webhook_token' => 'my-webhook-secret',
        ])
        ->assertRedirect(route('admin.connections.index'));

    $this->assertDatabaseHas('service_connections', [
        'type' => 'sonarr',
        'name' => 'My Sonarr',
        'url' => 'http://sonarr.local:8989',
    ]);

    $connection = ServiceConnection::first();
    expect($connection->api_key)->toBe('abc123def456');
    expect($connection->webhook_token)->toBe('my-webhook-secret');
});

test('store validates required fields', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.connections.store'), [])
        ->assertSessionHasErrors(['type', 'name', 'url', 'api_key', 'webhook_token']);
});

test('store validates service type', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.connections.store'), [
            'type' => 'invalid',
            'name' => 'Test',
            'url' => 'http://example.com',
            'api_key' => 'key',
            'webhook_token' => 'token12345',
        ])
        ->assertSessionHasErrors('type');
});

test('store validates url format', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.connections.store'), [
            'type' => 'sonarr',
            'name' => 'Test',
            'url' => 'not-a-url',
            'api_key' => 'key',
            'webhook_token' => 'token12345',
        ])
        ->assertSessionHasErrors('url');
});

test('admin can view edit form', function (): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->sonarr()->create([
        'api_key' => 'secret-api-key',
        'webhook_token' => 'secret-webhook-token',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.connections.edit', $connection))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Connections/Edit')
            ->has('connection')
            ->has('serviceTypes')
            // Secrets must NOT be passed as raw strings to the client.
            ->missing('connection.api_key')
            ->missing('connection.webhook_token')
            ->where('connection.api_key_set', true)
            ->where('connection.webhook_token_set', true)
        );
});

test('edit form exposes has-value booleans when secrets are empty', function (): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->sonarr()->create([
        'api_key' => '',
        'webhook_token' => '',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.connections.edit', $connection))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('connection.api_key_set', false)
            ->where('connection.webhook_token_set', false)
        );
});

test('admin can update a service connection', function (): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->sonarr()->create();

    $this->actingAs($admin)
        ->put(route('admin.connections.update', $connection), [
            'type' => 'sonarr',
            'name' => 'Updated Sonarr',
            'url' => 'http://new-sonarr.local:8989',
            'api_key' => 'new-key',
            'webhook_token' => 'new-token-12345',
        ])
        ->assertRedirect(route('admin.connections.index'));

    $connection->refresh();
    expect($connection->name)->toBe('Updated Sonarr');
    expect($connection->url)->toBe('http://new-sonarr.local:8989');
    expect($connection->api_key)->toBe('new-key');
    expect($connection->webhook_token)->toBe('new-token-12345');
});

test('update with blank api_key keeps the existing value', function (): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->sonarr()->create([
        'api_key' => 'original-api-key',
        'webhook_token' => 'original-webhook-token',
    ]);

    $this->actingAs($admin)
        ->put(route('admin.connections.update', $connection), [
            'type' => 'sonarr',
            'name' => 'Renamed',
            'url' => 'http://sonarr.local:8989',
            'api_key' => '',
            'webhook_token' => '',
        ])
        ->assertRedirect(route('admin.connections.index'));

    $connection->refresh();
    expect($connection->name)->toBe('Renamed');
    expect($connection->api_key)->toBe('original-api-key');
    expect($connection->webhook_token)->toBe('original-webhook-token');
});

test('update with missing secret keys keeps the existing values', function (): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->sonarr()->create([
        'api_key' => 'original-api-key',
        'webhook_token' => 'original-webhook-token',
    ]);

    $this->actingAs($admin)
        ->put(route('admin.connections.update', $connection), [
            'type' => 'sonarr',
            'name' => 'Still Working',
            'url' => 'http://sonarr.local:8989',
        ])
        ->assertRedirect(route('admin.connections.index'));

    $connection->refresh();
    expect($connection->api_key)->toBe('original-api-key');
    expect($connection->webhook_token)->toBe('original-webhook-token');
});

test('admin can delete a service connection', function (): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->sonarr()->create();

    $this->actingAs($admin)
        ->delete(route('admin.connections.destroy', $connection))
        ->assertRedirect(route('admin.connections.index'));

    $this->assertDatabaseMissing('service_connections', ['id' => $connection->id]);
});

test('admin can toggle connection active status', function (): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->sonarr()->create(['is_active' => true]);

    $this->actingAs($admin)
        ->patch(route('admin.connections.toggle', $connection))
        ->assertRedirect(route('admin.connections.index'));

    $connection->refresh();
    expect($connection->is_active)->toBeFalse();
});

test('admin can test a sonarr connection successfully', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'sonarr.local:8989/api/v3/system/status' => Http::response([
            'appName' => 'Sonarr',
            'version' => '4.0.0.1',
        ]),
    ]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->postJson(route('admin.connections.test'), [
            'type' => 'sonarr',
            'url' => 'http://sonarr.local:8989',
            'api_key' => 'test-key',
        ])
        ->assertSuccessful()
        ->assertJson([
            'success' => true,
            'version' => '4.0.0.1',
        ]);
});

test('admin can test an emby connection successfully', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'emby.local:8096/System/Info' => Http::response([
            'ServerName' => 'MyEmby',
            'Version' => '4.8.0.0',
        ]),
    ]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->postJson(route('admin.connections.test'), [
            'type' => 'emby',
            'url' => 'http://emby.local:8096',
            'api_key' => 'test-key',
        ])
        ->assertSuccessful()
        ->assertJson([
            'success' => true,
            'version' => '4.8.0.0',
        ]);
});

test('test connection returns failure on unreachable service', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'sonarr.local:8989/api/v3/system/status' => Http::response([], 500),
    ]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->postJson(route('admin.connections.test'), [
            'type' => 'sonarr',
            'url' => 'http://sonarr.local:8989',
            'api_key' => 'bad-key',
        ])
        ->assertUnprocessable()
        ->assertJson(['success' => false]);
});

test('test connection validates required fields', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->postJson(route('admin.connections.test'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['type', 'url', 'api_key']);
});

test('guests cannot test connections', function (): void {
    $this->postJson(route('admin.connections.test'), [
        'type' => 'sonarr',
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'key',
    ])->assertUnauthorized();
});

test('admin can dispatch a health check for a connection', function (): void {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->sonarr()->create();
    Queue::fake();

    $this->actingAs($admin)
        ->from(route('admin.connections.index'))
        ->post(route('admin.connections.check-health', $connection))
        ->assertRedirect(route('admin.connections.index'));

    Queue::assertPushed(PingServiceHealth::class, 1);
});

test('admin can dispatch a version check for a connection', function (): void {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->sonarr()->create();
    Queue::fake();

    $this->actingAs($admin)
        ->from(route('admin.connections.index'))
        ->post(route('admin.connections.check-version', $connection))
        ->assertRedirect(route('admin.connections.index'));

    Queue::assertPushed(FetchLatestServiceVersion::class, 1);
});

test('non-admin cannot dispatch health check', function (): void {
    $member = User::factory()->member()->create();
    $connection = ServiceConnection::factory()->sonarr()->create();

    $this->actingAs($member)
        ->post(route('admin.connections.check-health', $connection))
        ->assertForbidden();
});

test('non-admin cannot dispatch version check', function (): void {
    $member = User::factory()->member()->create();
    $connection = ServiceConnection::factory()->sonarr()->create();

    $this->actingAs($member)
        ->post(route('admin.connections.check-version', $connection))
        ->assertForbidden();
});
