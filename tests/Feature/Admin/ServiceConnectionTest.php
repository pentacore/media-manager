<?php

use App\Enums\BazarrServiceRole;
use App\Enums\ServiceType;
use App\Jobs\FetchLatestServiceVersion;
use App\Jobs\PingServiceHealth;
use App\Models\BazarrServiceLink;
use App\Models\ServiceConnection;
use App\Enums\SubtitleCaseStatus;
use App\Models\SubtitleCase;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

test('Bazarr is an available service connection type', function (): void {
    expect(ServiceType::Bazarr->value)->toBe('bazarr')
        ->and(ServiceType::Bazarr->label())->toBe('Bazarr')
        ->and(ServiceType::Bazarr->supportsWebhookConfiguration())->toBeFalse()
        ->and(ServiceConnection::factory()->bazarr()->make()->type)->toBe(ServiceType::Bazarr);
});

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

test('create form exposes only active Arr connections in a safe ordered shape', function (): void {
    $admin = User::factory()->admin()->create();
    $sonarr = ServiceConnection::factory()->sonarr()->create([
        'name' => 'Zulu Sonarr',
        'api_key' => 'sonarr-secret',
        'webhook_token' => 'sonarr-webhook-secret',
    ]);
    $radarr = ServiceConnection::factory()->radarr()->create([
        'name' => 'Alpha Radarr',
        'api_key' => 'radarr-secret',
        'webhook_token' => 'radarr-webhook-secret',
    ]);
    ServiceConnection::factory()->sonarr()->inactive()->create(['name' => 'Inactive Sonarr']);
    ServiceConnection::factory()->bazarr()->create(['name' => 'Bazarr']);
    ServiceConnection::factory()->emby()->create(['name' => 'Emby']);

    $this->actingAs($admin)
        ->get(route('admin.connections.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
            ->where('arrConnections', [
                ['id' => $radarr->id, 'type' => ServiceType::Radarr->value, 'name' => 'Alpha Radarr'],
                ['id' => $sonarr->id, 'type' => ServiceType::Sonarr->value, 'name' => 'Zulu Sonarr'],
            ])
            ->missing('arrConnections.0.api_key')
            ->missing('arrConnections.0.webhook_token')
            ->missing('arrConnections.1.api_key')
            ->missing('arrConnections.1.webhook_token')
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

test('store rejects a Bazarr connection without a Sonarr or Radarr mapping', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.connections.store'), [
            'type' => 'bazarr',
            'name' => 'My Bazarr',
            'url' => 'http://bazarr.local:6767',
            'api_key' => 'abc123def456',
            'webhook_token' => 'my-webhook-secret',
        ])
        ->assertSessionHasErrors([
            'sonarr_connection_id' => 'Select a Sonarr or Radarr connection.',
            'radarr_connection_id' => 'Select a Sonarr or Radarr connection.',
        ]);
});

test('store rejects a Radarr connection used as the Sonarr mapping', function (): void {
    $admin = User::factory()->admin()->create();
    $radarr = ServiceConnection::factory()->radarr()->create();

    $this->actingAs($admin)
        ->post(route('admin.connections.store'), [
            'type' => 'bazarr',
            'name' => 'My Bazarr',
            'url' => 'http://bazarr.local:6767',
            'api_key' => 'abc123def456',
            'webhook_token' => 'my-webhook-secret',
            'sonarr_connection_id' => $radarr->id,
        ])
        ->assertSessionHasErrors([
            'sonarr_connection_id' => 'The selected connection has the wrong service type.',
        ]);
});

test('admin can store a Bazarr connection with one valid mapping', function (): void {
    $admin = User::factory()->admin()->create();
    $sonarr = ServiceConnection::factory()->sonarr()->create();

    $this->actingAs($admin)
        ->post(route('admin.connections.store'), [
            'type' => 'bazarr',
            'name' => 'My Bazarr',
            'url' => 'http://bazarr.local:6767',
            'api_key' => 'abc123def456',
            'webhook_token' => 'my-webhook-secret',
            'sonarr_connection_id' => $sonarr->id,
        ])
        ->assertRedirect(route('admin.connections.index'))
        ->assertSessionHasNoErrors();

    $serviceConnection = ServiceConnection::query()->where('type', ServiceType::Bazarr)->sole();

    $this->assertDatabaseHas('bazarr_service_links', [
        'bazarr_connection_id' => $serviceConnection->id,
        'related_connection_id' => $sonarr->id,
        'role' => BazarrServiceRole::Sonarr->value,
    ]);
});

test('non-Bazarr store excludes malformed mapping fields', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.connections.store'), [
            'type' => 'emby',
            'name' => 'My Emby',
            'url' => 'http://emby.local:8096',
            'api_key' => 'abc123def456',
            'webhook_token' => 'my-webhook-secret',
            'sonarr_connection_id' => 'junk',
            'radarr_connection_id' => -1,
        ])
        ->assertRedirect(route('admin.connections.index'))
        ->assertSessionHasNoErrors();

    $serviceConnection = ServiceConnection::query()->where('type', ServiceType::Emby)->sole();

    expect($serviceConnection->bazarrServiceLinks()->doesntExist())->toBeTrue()
        ->and($serviceConnection->incomingBazarrServiceLinks()->doesntExist())->toBeTrue();
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
            ->where('connection.sonarr_connection_id', null)
            ->where('connection.radarr_connection_id', null)
        );
});

test('Bazarr edit form exposes safe Arr options and selected mappings', function (): void {
    $admin = User::factory()->admin()->create();
    $bazarr = ServiceConnection::factory()->bazarr()->create([
        'api_key' => 'bazarr-secret',
        'webhook_token' => 'bazarr-webhook-secret',
    ]);
    $sonarr = ServiceConnection::factory()->sonarr()->create([
        'name' => 'Zulu Sonarr',
        'api_key' => 'sonarr-secret',
        'webhook_token' => 'sonarr-webhook-secret',
    ]);
    $radarr = ServiceConnection::factory()->radarr()->create([
        'name' => 'Alpha Radarr',
        'api_key' => 'radarr-secret',
        'webhook_token' => 'radarr-webhook-secret',
    ]);
    ServiceConnection::factory()->radarr()->inactive()->create(['name' => 'Inactive Radarr']);
    ServiceConnection::factory()->prowlarr()->create(['name' => 'Prowlarr']);
    BazarrServiceLink::factory()->sonarr()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $sonarr->id,
    ]);
    BazarrServiceLink::factory()->radarr()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $radarr->id,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.connections.edit', $bazarr))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
            ->where('arrConnections', [
                ['id' => $radarr->id, 'type' => ServiceType::Radarr->value, 'name' => 'Alpha Radarr'],
                ['id' => $sonarr->id, 'type' => ServiceType::Sonarr->value, 'name' => 'Zulu Sonarr'],
            ])
            ->where('connection.sonarr_connection_id', $sonarr->id)
            ->where('connection.radarr_connection_id', $radarr->id)
            ->missing('connection.api_key')
            ->missing('connection.webhook_token')
            ->missing('arrConnections.0.api_key')
            ->missing('arrConnections.0.webhook_token')
            ->missing('arrConnections.1.api_key')
            ->missing('arrConnections.1.webhook_token')
        );
});

test('edit form excludes the connection being edited from Arr mapping options', function (ServiceType $serviceType): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->create([
        'type' => $serviceType,
        'name' => 'Connection Being Edited',
    ]);
    $otherConnection = ServiceConnection::factory()->create([
        'type' => $serviceType === ServiceType::Sonarr ? ServiceType::Radarr : ServiceType::Sonarr,
        'name' => 'Other Arr Connection',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.connections.edit', $connection))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
            ->where('arrConnections', [[
                'id' => $otherConnection->id,
                'type' => $otherConnection->type->value,
                'name' => 'Other Arr Connection',
            ]]));
})->with([
    'Sonarr' => ServiceType::Sonarr,
    'Radarr' => ServiceType::Radarr,
]);

test('Bazarr edit form includes only the selected inactive Arr mapping', function (): void {
    $admin = User::factory()->admin()->create();
    $bazarr = ServiceConnection::factory()->bazarr()->create();
    $selectedSonarr = ServiceConnection::factory()->sonarr()->create(['name' => 'Selected Sonarr']);
    ServiceConnection::factory()->sonarr()->inactive()->create(['name' => 'Arbitrary Inactive Sonarr']);
    $activeRadarr = ServiceConnection::factory()->radarr()->create(['name' => 'Active Radarr']);
    BazarrServiceLink::factory()->sonarr()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $selectedSonarr->id,
    ]);
    $selectedSonarr->update(['is_active' => false]);

    $this->actingAs($admin)
        ->get(route('admin.connections.edit', $bazarr))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
            ->where('arrConnections', [
                ['id' => $activeRadarr->id, 'type' => ServiceType::Radarr->value, 'name' => 'Active Radarr'],
                ['id' => $selectedSonarr->id, 'type' => ServiceType::Sonarr->value, 'name' => 'Selected Sonarr (inactive)'],
            ])
            ->where('connection.sonarr_connection_id', $selectedSonarr->id)
            ->where('connection.radarr_connection_id', null));
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

test('non-Bazarr update excludes malformed mapping fields', function (): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->emby()->create();

    $this->actingAs($admin)
        ->put(route('admin.connections.update', $connection), [
            'type' => 'emby',
            'name' => 'Updated Emby',
            'url' => $connection->url,
            'sonarr_connection_id' => 'junk',
            'radarr_connection_id' => -1,
        ])
        ->assertRedirect(route('admin.connections.index'))
        ->assertSessionHasNoErrors();

    expect($connection->refresh()->name)->toBe('Updated Emby')
        ->and($connection->bazarrServiceLinks()->doesntExist())->toBeTrue()
        ->and($connection->incomingBazarrServiceLinks()->doesntExist())->toBeTrue();
});

test('converting an Arr connection to Bazarr rejects mapping it to itself', function (
    ServiceType $serviceType,
    string $mappingField,
): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->create(['type' => $serviceType]);

    $this->actingAs($admin)
        ->put(route('admin.connections.update', $connection), [
            'type' => ServiceType::Bazarr->value,
            'name' => $connection->name,
            'url' => $connection->url,
            $mappingField => $connection->id,
        ])
        ->assertSessionHasErrors([
            $mappingField => 'A service connection cannot be mapped to itself.',
        ]);

    expect($connection->refresh()->type)->toBe($serviceType)
        ->and($connection->bazarrServiceLinks()->doesntExist())->toBeTrue();
})->with([
    'Sonarr' => [ServiceType::Sonarr, 'sonarr_connection_id'],
    'Radarr' => [ServiceType::Radarr, 'radarr_connection_id'],
]);

test('updating Bazarr preserves its selected inactive Arr mapping', function (): void {
    $admin = User::factory()->admin()->create();
    $bazarr = ServiceConnection::factory()->bazarr()->create();
    $sonarr = ServiceConnection::factory()->sonarr()->create();
    $link = BazarrServiceLink::factory()->sonarr()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $sonarr->id,
    ]);
    $sonarr->update(['is_active' => false]);

    $this->actingAs($admin)
        ->put(route('admin.connections.update', $bazarr), [
            'type' => ServiceType::Bazarr->value,
            'name' => 'Updated Bazarr',
            'url' => $bazarr->url,
            'sonarr_connection_id' => $sonarr->id,
        ])
        ->assertRedirect(route('admin.connections.index'))
        ->assertSessionHasNoErrors();

    $this->assertModelExists($link);
    expect($bazarr->refresh()->name)->toBe('Updated Bazarr');
});

test('admin can add replace and remove omitted Bazarr mappings', function (): void {
    $admin = User::factory()->admin()->create();
    $bazarr = ServiceConnection::factory()->bazarr()->create();
    $firstSonarr = ServiceConnection::factory()->sonarr()->create();
    $secondSonarr = ServiceConnection::factory()->sonarr()->create();
    $radarr = ServiceConnection::factory()->radarr()->create();

    $payload = [
        'type' => 'bazarr',
        'name' => $bazarr->name,
        'url' => $bazarr->url,
    ];

    $this->actingAs($admin)
        ->put(route('admin.connections.update', $bazarr), [
            ...$payload,
            'sonarr_connection_id' => $firstSonarr->id,
            'radarr_connection_id' => $radarr->id,
        ])
        ->assertRedirect(route('admin.connections.index'))
        ->assertSessionHasNoErrors();

    expect($bazarr->mappedConnection(BazarrServiceRole::Sonarr)?->is($firstSonarr))->toBeTrue()
        ->and($bazarr->mappedConnection(BazarrServiceRole::Radarr)?->is($radarr))->toBeTrue();

    $this->actingAs($admin)
        ->put(route('admin.connections.update', $bazarr), [
            ...$payload,
            'sonarr_connection_id' => $secondSonarr->id,
        ])
        ->assertRedirect(route('admin.connections.index'))
        ->assertSessionHasNoErrors();

    expect($bazarr->mappedConnection(BazarrServiceRole::Sonarr)?->is($secondSonarr))->toBeTrue()
        ->and($bazarr->mappedConnection(BazarrServiceRole::Radarr))->toBeNull();
});

test('changing a Bazarr connection to another type removes all mappings', function (): void {
    $admin = User::factory()->admin()->create();
    $bazarr = ServiceConnection::factory()->bazarr()->create();
    $sonarr = ServiceConnection::factory()->sonarr()->create();
    $link = BazarrServiceLink::factory()->sonarr()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $sonarr->id,
    ]);

    $this->actingAs($admin)
        ->put(route('admin.connections.update', $bazarr), [
            'type' => 'emby',
            'name' => 'Now Emby',
            'url' => 'http://emby.local:8096',
        ])
        ->assertRedirect(route('admin.connections.index'))
        ->assertSessionHasNoErrors();

    expect($bazarr->refresh()->type)->toBe(ServiceType::Emby);
    $this->assertModelMissing($link);
});

test('changing mapped arr connection types removes their incoming Bazarr mappings', function (): void {
    $admin = User::factory()->admin()->create();
    $sonarrBazarr = ServiceConnection::factory()->bazarr()->create();
    $radarrBazarr = ServiceConnection::factory()->bazarr()->create();
    $sonarr = ServiceConnection::factory()->sonarr()->create();
    $radarr = ServiceConnection::factory()->radarr()->create();
    $sonarrLink = BazarrServiceLink::factory()->sonarr()->create([
        'bazarr_connection_id' => $sonarrBazarr->id,
        'related_connection_id' => $sonarr->id,
    ]);
    $radarrLink = BazarrServiceLink::factory()->radarr()->create([
        'bazarr_connection_id' => $radarrBazarr->id,
        'related_connection_id' => $radarr->id,
    ]);

    $this->actingAs($admin)
        ->put(route('admin.connections.update', $sonarr), [
            'type' => 'emby',
            'name' => 'Former Sonarr',
            'url' => $sonarr->url,
        ])
        ->assertRedirect(route('admin.connections.index'))
        ->assertSessionHasNoErrors();

    $this->actingAs($admin)
        ->put(route('admin.connections.update', $radarr), [
            'type' => 'prowlarr',
            'name' => 'Former Radarr',
            'url' => $radarr->url,
        ])
        ->assertRedirect(route('admin.connections.index'))
        ->assertSessionHasNoErrors();

    $this->assertModelMissing($sonarrLink);
    $this->assertModelMissing($radarrLink);
});

test('updating a mapped arr connection without changing its type preserves the mapping', function (): void {
    $admin = User::factory()->admin()->create();
    $bazarr = ServiceConnection::factory()->bazarr()->create();
    $sonarr = ServiceConnection::factory()->sonarr()->create();
    $link = BazarrServiceLink::factory()->sonarr()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $sonarr->id,
    ]);

    $this->actingAs($admin)
        ->put(route('admin.connections.update', $sonarr), [
            'type' => 'sonarr',
            'name' => 'Renamed Sonarr',
            'url' => $sonarr->url,
        ])
        ->assertRedirect(route('admin.connections.index'))
        ->assertSessionHasNoErrors();

    $this->assertModelExists($link);
});

test('Sonarr root-folder classifications are stored on their connection', function (): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->sonarr()->create([
        'settings' => ['disk' => ['mode' => 'all', 'paths' => [], 'display' => []]],
    ]);
    $otherConnection = ServiceConnection::factory()->sonarr()->create([
        'settings' => [
            'sonarr_root_folders' => [[
                'root_folder_id' => 9,
                'path' => '/other-anime',
                'scope' => 'anime',
            ]],
        ],
    ]);

    $this->actingAs($admin)
        ->put(route('admin.connections.update', $connection), [
            'type' => 'sonarr',
            'name' => $connection->name,
            'url' => $connection->url,
            'sonarr_root_folders' => [
                ['root_folder_id' => 1, 'path' => '/tv', 'scope' => 'tv'],
                ['root_folder_id' => 2, 'path' => '/anime', 'scope' => 'anime'],
            ],
        ])
        ->assertRedirect(route('admin.connections.index'))
        ->assertSessionHasNoErrors();

    expect($connection->refresh()->settings)
        ->toMatchArray([
            'disk' => ['mode' => 'all', 'paths' => [], 'display' => []],
            'sonarr_root_folders' => [
                ['root_folder_id' => 1, 'path' => '/tv', 'scope' => 'tv'],
                ['root_folder_id' => 2, 'path' => '/anime', 'scope' => 'anime'],
            ],
        ])
        ->and($otherConnection->refresh()->settings['sonarr_root_folders'])->toBe([[
            'root_folder_id' => 9,
            'path' => '/other-anime',
            'scope' => 'anime',
        ]]);
});

test('Sonarr root-folder classifications reject unsupported scopes', function (): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->sonarr()->create();

    $this->actingAs($admin)
        ->put(route('admin.connections.update', $connection), [
            'type' => 'sonarr',
            'name' => $connection->name,
            'url' => $connection->url,
            'sonarr_root_folders' => [[
                'root_folder_id' => 2,
                'path' => '/anime',
                'scope' => 'movie',
            ]],
        ])
        ->assertSessionHasErrors('sonarr_root_folders.0.scope');
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

test('admin cannot delete a Bazarr connection referenced by subtitle workflow history', function (): void {
    $admin = User::factory()->admin()->create();
    $case = SubtitleCase::factory()->create();
    $connection = $case->bazarrConnection;

    $this->actingAs($admin)
        ->delete(route('admin.connections.destroy', $connection))
        ->assertRedirect(route('admin.connections.index'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error')
        ->assertSessionHas(
            'inertia.flash_data.toast.message',
            'Connection cannot be deleted because subtitle workflow history references it.',
        );

    $this->assertModelExists($connection);
    expect($connection->bazarrSubtitleCases()->sole()->is($case))->toBeTrue();
});

test('admin cannot delete a managing connection referenced by subtitle workflow history', function (): void {
    $admin = User::factory()->admin()->create();
    $case = SubtitleCase::factory()->create();
    $connection = $case->serviceConnection;

    $this->actingAs($admin)
        ->delete(route('admin.connections.destroy', $connection))
        ->assertRedirect(route('admin.connections.index'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error')
        ->assertSessionHas(
            'inertia.flash_data.toast.message',
            'Connection cannot be deleted because subtitle workflow history references it.',
        );

    $this->assertModelExists($connection);
    expect($connection->managedSubtitleCases()->sole()->is($case))->toBeTrue();
});

test('a concurrent subtitle case reference is handled during connection deletion', function (): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->sonarr()->create();
    $bazarr = ServiceConnection::factory()->bazarr()->create();

    ServiceConnection::deleting(function (ServiceConnection $serviceConnection) use ($bazarr, $connection): void {
        if (! $serviceConnection->is($connection)) {
            return;
        }

        SubtitleCase::factory()->create([
            'bazarr_connection_id' => $bazarr->id,
            'service_connection_id' => $connection->id,
        ]);
    });

    $this->actingAs($admin)
        ->delete(route('admin.connections.destroy', $connection))
        ->assertRedirect(route('admin.connections.index'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');

    $this->assertModelExists($connection);
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

test('removing a Bazarr mapping supersedes the active cases for that pairing', function (): void {
    $admin = User::factory()->admin()->create();
    $bazarr = ServiceConnection::factory()->bazarr()->create();
    $sonarr = ServiceConnection::factory()->sonarr()->create();
    $radarr = ServiceConnection::factory()->radarr()->create();
    BazarrServiceLink::factory()->sonarr()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $sonarr->id,
    ]);
    BazarrServiceLink::factory()->radarr()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $radarr->id,
    ]);
    $case = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'service_connection_id' => $sonarr->id,
        'status' => SubtitleCaseStatus::BazarrSearching,
    ]);

    $this->actingAs($admin)
        ->put(route('admin.connections.update', $bazarr), [
            'type' => 'bazarr',
            'name' => $bazarr->name,
            'url' => $bazarr->url,
            'radarr_connection_id' => $radarr->id,
        ])
        ->assertRedirect(route('admin.connections.index'))
        ->assertSessionHasNoErrors();

    expect($bazarr->mappedConnection(BazarrServiceRole::Sonarr))->toBeNull()
        ->and($case->fresh()->status)->toBe(SubtitleCaseStatus::Superseded);
});

test('deactivating a Bazarr connection supersedes its active cases', function (): void {
    $admin = User::factory()->admin()->create();
    $bazarr = ServiceConnection::factory()->bazarr()->create(['is_active' => true]);
    $sonarr = ServiceConnection::factory()->sonarr()->create();
    $case = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'service_connection_id' => $sonarr->id,
        'status' => SubtitleCaseStatus::DownloadRequested,
    ]);

    $this->actingAs($admin)
        ->patch(route('admin.connections.toggle', $bazarr))
        ->assertRedirect(route('admin.connections.index'));

    expect($bazarr->fresh()->is_active)->toBeFalse()
        ->and($case->fresh()->status)->toBe(SubtitleCaseStatus::Superseded);
});

test('deactivating a mapped Sonarr connection supersedes the cases it manages', function (): void {
    $admin = User::factory()->admin()->create();
    $bazarr = ServiceConnection::factory()->bazarr()->create();
    $sonarr = ServiceConnection::factory()->sonarr()->create(['is_active' => true]);
    BazarrServiceLink::factory()->sonarr()->create([
        'bazarr_connection_id' => $bazarr->id,
        'related_connection_id' => $sonarr->id,
    ]);
    $case = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'service_connection_id' => $sonarr->id,
        'status' => SubtitleCaseStatus::BazarrSearching,
    ]);

    $this->actingAs($admin)
        ->patch(route('admin.connections.toggle', $sonarr))
        ->assertRedirect(route('admin.connections.index'));

    expect($case->fresh()->status)->toBe(SubtitleCaseStatus::Superseded);
});

test('supersession never regresses terminal cases and never touches unrelated cases', function (): void {
    $admin = User::factory()->admin()->create();
    $bazarr = ServiceConnection::factory()->bazarr()->create(['is_active' => true]);
    $sonarr = ServiceConnection::factory()->sonarr()->create();

    $resolved = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'service_connection_id' => $sonarr->id,
        'status' => SubtitleCaseStatus::Resolved,
    ]);
    $dismissed = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'service_connection_id' => $sonarr->id,
        'status' => SubtitleCaseStatus::Dismissed,
    ]);
    $handled = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $bazarr->id,
        'service_connection_id' => $sonarr->id,
        'status' => SubtitleCaseStatus::Handled,
    ]);

    $otherBazarr = ServiceConnection::factory()->bazarr()->create();
    $unrelated = SubtitleCase::factory()->create([
        'bazarr_connection_id' => $otherBazarr->id,
        'status' => SubtitleCaseStatus::BazarrSearching,
    ]);

    $this->actingAs($admin)
        ->patch(route('admin.connections.toggle', $bazarr))
        ->assertRedirect(route('admin.connections.index'));

    expect($resolved->fresh()->status)->toBe(SubtitleCaseStatus::Resolved)
        ->and($dismissed->fresh()->status)->toBe(SubtitleCaseStatus::Dismissed)
        ->and($handled->fresh()->status)->toBe(SubtitleCaseStatus::Handled)
        ->and($unrelated->fresh()->status)->toBe(SubtitleCaseStatus::BazarrSearching);
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

test('edit page exposes indexers prop populated for a Prowlarr connection', function (): void {
    config()->set('inertia.ssr.enabled', false);
    Http::preventStrayRequests();

    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->prowlarr()->create([
        'url' => 'http://prowlarr.local:9696',
        'api_key' => 'test',
    ]);

    Http::fake([
        'prowlarr.local:9696/api/v1/indexer' => Http::response([
            ['id' => 1, 'name' => 'Demo One', 'enable' => true, 'priority' => 25, 'fields' => [['name' => 'apiKey', 'value' => 'SECRET']]],
            ['id' => 2, 'name' => 'Demo Two', 'enable' => false, 'priority' => 50],
        ]),
    ]);

    $this->actingAs($admin)
        ->get(sprintf('/admin/connections/%d/edit', $connection->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
            ->component('Admin/Connections/Edit')
            ->has('connection')
            ->loadDeferredProps(fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
                ->has('indexers', 2)
                ->where('indexers.0.name', 'Demo One')
                ->where('indexers.0.priority', 25)
                ->missing('indexers.0.fields')
                ->missing('indexers.0.settings')));
});

test('edit page falls back to empty indexers when Prowlarr is unreachable', function (): void {
    config()->set('inertia.ssr.enabled', false);
    Http::preventStrayRequests();

    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->prowlarr()->create([
        'url' => 'http://prowlarr.local:9696',
        'api_key' => 'test',
    ]);

    Http::fake([
        'prowlarr.local:9696/api/v1/indexer' => Http::response([], 500),
    ]);

    $this->actingAs($admin)
        ->get(sprintf('/admin/connections/%d/edit', $connection->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
            ->component('Admin/Connections/Edit')
            ->loadDeferredProps(fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
                ->where('indexers', [])));
});

test('Sonarr edit page imports and classifies only that connection root folders', function (): void {
    config()->set('inertia.ssr.enabled', false);
    Http::preventStrayRequests();

    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://primary-sonarr.local:8989',
        'api_key' => 'test',
        'settings' => [
            'sonarr_root_folders' => [[
                'root_folder_id' => 2,
                'path' => '/old-anime-path',
                'scope' => 'anime',
            ]],
        ],
    ]);
    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://other-sonarr.local:8989',
        'api_key' => 'test',
        'settings' => [
            'sonarr_root_folders' => [[
                'root_folder_id' => 9,
                'path' => '/other-library',
                'scope' => 'tv',
            ]],
        ],
    ]);

    Http::fake([
        'primary-sonarr.local:8989/api/v3/rootfolder' => Http::response([
            ['id' => 1, 'path' => '/tv'],
            ['id' => 2, 'path' => '/anime'],
        ]),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.connections.edit', $connection))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
            ->component('Admin/Connections/Edit')
            ->loadDeferredProps(fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
                ->where('sonarrRootFolders', [
                    ['root_folder_id' => 1, 'path' => '/tv', 'scope' => null],
                    ['root_folder_id' => 2, 'path' => '/anime', 'scope' => 'anime'],
                ])));
});

test('linkUrl prefers external_url and trims trailing slash', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.internal:8989',
        'external_url' => 'https://sonarr.example.com/',
    ]);

    expect($connection->linkUrl())->toBe('https://sonarr.example.com');
});

test('linkUrl falls back to url when external_url is unset', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.internal:8989/',
        'external_url' => null,
    ]);

    expect($connection->linkUrl())->toBe('http://sonarr.internal:8989');
});

test('store persists external_url', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.connections.store'), [
            'type' => 'sonarr',
            'name' => 'My Sonarr',
            'url' => 'http://sonarr.local:8989',
            'external_url' => 'https://sonarr.example.com',
            'api_key' => 'abc123def456',
            'webhook_token' => 'my-webhook-secret',
        ])
        ->assertRedirect(route('admin.connections.index'));

    $this->assertDatabaseHas('service_connections', [
        'name' => 'My Sonarr',
        'external_url' => 'https://sonarr.example.com',
    ]);
});

test('store validates external_url format', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.connections.store'), [
            'type' => 'sonarr',
            'name' => 'Test',
            'url' => 'http://sonarr.local:8989',
            'external_url' => 'not-a-url',
            'api_key' => 'key',
            'webhook_token' => 'token12345',
        ])
        ->assertSessionHasErrors('external_url');
});

test('update can set and clear external_url', function (): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->sonarr()->create();

    $payload = [
        'type' => 'sonarr',
        'name' => 'Sonarr',
        'url' => 'http://sonarr.local:8989',
    ];

    $this->actingAs($admin)
        ->put(route('admin.connections.update', $connection), [
            ...$payload,
            'external_url' => 'https://sonarr.example.com',
        ])
        ->assertRedirect(route('admin.connections.index'));

    expect($connection->refresh()->external_url)->toBe('https://sonarr.example.com');

    $this->actingAs($admin)
        ->put(route('admin.connections.update', $connection), [
            ...$payload,
            'external_url' => null,
        ])
        ->assertRedirect(route('admin.connections.index'));

    expect($connection->refresh()->external_url)->toBeNull();
});

test('edit form exposes external_url', function (): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->sonarr()->create([
        'external_url' => 'https://sonarr.example.com',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.connections.edit', $connection))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('connection.external_url', 'https://sonarr.example.com')
        );
});
