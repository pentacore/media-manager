<?php

use App\Models\ServiceConnection;
use App\Models\User;

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
