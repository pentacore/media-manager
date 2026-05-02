<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('non-admin cannot configure webhook', function (): void {
    $user = User::factory()->member()->create();
    $connection = ServiceConnection::factory()->sonarr()->create();

    $this->actingAs($user)
        ->post(route('admin.connections.configure-webhook', $connection))
        ->assertForbidden();
});

test('admin can configure webhook on a sonarr connection', function (): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local',
        'webhook_token' => 'secret-token',
    ]);

    Http::fake([
        'sonarr.local/api/v3/notification' => Http::sequence()
            ->push([], 200)
            ->push(['id' => 7, 'name' => 'MediaManager'], 201),
    ]);

    $this->actingAs($admin)
        ->from(route('admin.connections.edit', $connection))
        ->post(route('admin.connections.configure-webhook', $connection))
        ->assertRedirect(route('admin.connections.edit', $connection))
        ->assertSessionHas('success');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with((string) $request->url(), '/api/v3/notification')
        && collect($request->data()['fields'])->firstWhere('name', 'url')['value']
            === route('webhooks.handle', [
                'service' => 'sonarr',
                'connection' => $connection->id,
            ]).'?token=secret-token');
});

test('configure webhook is a no-op (422) for service types without notification API', function (): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->emby()->create();

    $this->actingAs($admin)
        ->from(route('admin.connections.edit', $connection))
        ->post(route('admin.connections.configure-webhook', $connection))
        ->assertRedirect(route('admin.connections.edit', $connection))
        ->assertSessionHasErrors(['configure_webhook']);
});

test('configure webhook surfaces upstream failure as a flash error', function (): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local',
    ]);

    Http::fake([
        'sonarr.local/api/v3/notification' => Http::response('boom', 500),
    ]);

    $this->actingAs($admin)
        ->from(route('admin.connections.edit', $connection))
        ->post(route('admin.connections.configure-webhook', $connection))
        ->assertRedirect(route('admin.connections.edit', $connection))
        ->assertSessionHasErrors(['configure_webhook']);
});
