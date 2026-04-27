<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('non-admin cannot trigger an indexer test', function (): void {
    $connection = ServiceConnection::factory()->prowlarr()->create();
    $member = User::factory()->member()->create();

    $this->actingAs($member)
        ->post(sprintf('/admin/connections/%d/prowlarr/test-indexer/1', $connection->id))
        ->assertForbidden();
});

test('admin can test an indexer and gets a success response', function (): void {
    $connection = ServiceConnection::factory()->prowlarr()->create([
        'url' => 'http://prowlarr.local:9696',
        'api_key' => 'test',
    ]);
    $admin = User::factory()->admin()->create();

    Http::fake([
        'prowlarr.local:9696/api/v1/indexer/42/test' => Http::response([], 200),
    ]);

    $this->actingAs($admin)
        ->post(sprintf('/admin/connections/%d/prowlarr/test-indexer/42', $connection->id))
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');
});

test('test request against a non-Prowlarr connection is rejected', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(sprintf('/admin/connections/%d/prowlarr/test-indexer/1', $connection->id))
        ->assertNotFound();
});

test('admin sees the failure errors when an indexer test fails', function (): void {
    $connection = ServiceConnection::factory()->prowlarr()->create([
        'url' => 'http://prowlarr.local:9696',
        'api_key' => 'test',
    ]);
    $admin = User::factory()->admin()->create();

    Http::fake([
        'prowlarr.local:9696/api/v1/indexer/42/test' => Http::response([
            ['propertyName' => 'baseUrl', 'errorMessage' => 'Unable to connect to indexer'],
        ], 400),
    ]);

    $response = $this->actingAs($admin)
        ->post(sprintf('/admin/connections/%d/prowlarr/test-indexer/42', $connection->id));

    $response->assertRedirect();
    $response->assertSessionHas('inertia.flash_data.toast.type', 'error');
});
