<?php

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

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
    $connection = ServiceConnection::factory()->sonarr()->create();

    $this->actingAs($admin)
        ->get(route('admin.connections.edit', $connection))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Connections/Edit')
            ->has('connection')
            ->has('serviceTypes')
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
