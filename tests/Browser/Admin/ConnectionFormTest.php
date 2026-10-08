<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * Characterisation coverage for resources/js/pages/Admin/Connections/Edit.vue
 * and Create.vue, written before Batch 3c moved their sections into
 * resources/js/components/connections/. The subtitle-check tag picker, Sonarr
 * library types and the Bazarr mapping create/edit flows are pinned elsewhere
 * (ConnectionSubtitleCheckTagsTest, SmokeTest).
 *
 * Scoped Http::fake patterns only, never a '*' catch-all: the catch-all also
 * answers Inertia's SSR POST with an empty body, which renders the page blank.
 */
/**
 * @param  array<int, array<string, mixed>>  $rootFolders
 * @param  array<int, array<string, mixed>>  $diskSpace
 */
function connectionFormFakeSonarr(
    int $statusCode = 200,
    array $rootFolders = [
        ['id' => 1, 'path' => '/tv'],
        ['id' => 2, 'path' => '/anime'],
    ],
    array $diskSpace = [
        ['path' => '/tv', 'label' => 'TV', 'freeSpace' => 100, 'totalSpace' => 200],
        ['path' => '/anime', 'label' => null, 'freeSpace' => 100, 'totalSpace' => 200],
    ],
): void {
    Http::fake([
        'sonarr.local:8989/api/v3/tag' => Http::response([['id' => 1, 'label' => 'sub-check']]),
        'sonarr.local:8989/api/v3/rootfolder' => Http::response($rootFolders),
        'sonarr.local:8989/api/v3/diskspace' => Http::response($diskSpace),
        'sonarr.local:8989/api/v3/system/status' => $statusCode === 200
            ? Http::response(['version' => '4.0.1'])
            : Http::response(['message' => 'Upstream exploded.'], $statusCode),
    ]);
}

/**
 * @param  array<string, mixed>  $settings
 */
function connectionFormSonarr(array $settings = []): ServiceConnection
{
    return ServiceConnection::factory()->sonarr()->create([
        'name' => 'Main Sonarr',
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'test',
        'webhook_token' => 'stored-token',
        'is_active' => true,
        'settings' => $settings,
    ]);
}

beforeEach(function (): void {
    $this->actingAs(User::factory()->admin()->create());
});

test('the sonarr edit form renders its fields and only the sonarr sections', function (): void {
    $serviceConnection = connectionFormSonarr();
    connectionFormFakeSonarr();
    $webhookUrl = route('webhooks.handle', ['service' => 'sonarr', 'connection' => $serviceConnection->id]).'?token=stored-token';

    visit(route('admin.connections.edit', $serviceConnection, absolute: false))
        ->assertNoSmoke()
        ->assertValue('#name', 'Main Sonarr')
        ->assertValue('#url', 'http://sonarr.local:8989')
        ->assertValue('#external_url', '')
        ->assertAttribute('#api_key', 'placeholder', '•••••••• (set — leave blank to keep)')
        ->assertAttribute('#api_key', 'autocomplete', 'new-password')
        ->assertSee('Leave blank to keep the existing value.')
        ->assertAttribute('#webhook_token', 'placeholder', '•••••••• (set — leave blank to keep)')
        ->assertAttribute('#webhook_token', 'type', 'password')
        ->assertValue('[data-webhook-url]', $webhookUrl)
        ->assertVisible('[data-webhook-url-copy]')
        ->assertVisible('[data-webhook-configure]')
        ->assertSeeIn('[data-disk-display]', 'Service Health · disk display')
        ->assertSeeIn('[data-sonarr-library-types]', '/anime')
        ->assertSeeIn('[data-subtitle-check-tags]', 'sub-check')
        ->assertMissing('[data-bazarr-mappings]')
        ->assertMissing('[data-whisparr-version]')
        ->assertMissing('[data-hidden-categories]')
        ->assertMissing('[data-sab-script]')
        ->assertMissing('[data-prowlarr-indexers]');
});

test('the disk display picker submits the chosen mode, paths and metrics', function (): void {
    $serviceConnection = connectionFormSonarr(['disk' => ['mode' => 'all', 'paths' => [], 'display' => []]]);
    connectionFormFakeSonarr();

    visit(route('admin.connections.edit', $serviceConnection, absolute: false))
        ->assertNoSmoke()
        ->assertMissing('[data-disk-path="/tv"]')
        ->click('Show selected')
        ->assertSeeIn('[data-disk-path="/tv"]', '(TV)')
        ->assertMissing('[data-disk-path="/tv"] [data-disk-metric="used"]')
        ->click('[data-disk-path="/tv"] input[type="checkbox"]')
        ->click('[data-disk-path="/tv"] [data-disk-metric="used"]')
        ->click('Sum selected')
        ->assertMissing('[data-disk-path="/tv"] [data-disk-metric="used"]')
        ->click('[data-disk-sum-metric="free"]')
        ->click('Update Connection')
        ->assertSee('Connection updated.');

    expect($serviceConnection->fresh()->settings['disk'])->toEqual([
        'mode' => 'sum',
        'paths' => ['/tv'],
        'display' => ['/tv' => 'used', 'sum' => 'free'],
    ]);
});

test('the connection test reports the upstream version once a key is entered', function (): void {
    $serviceConnection = connectionFormSonarr();
    connectionFormFakeSonarr();

    visit(route('admin.connections.edit', $serviceConnection, absolute: false))
        ->assertNoSmoke()
        ->assertSee('Enter the API key above to test the connection.')
        ->assertButtonDisabled('Test Connection')
        ->fill('api_key', 'test')
        ->assertDontSee('Enter the API key above to test the connection.')
        ->click('[data-connection-test]')
        ->assertSeeIn('[data-connection-test-result]', 'Connection successful.')
        ->assertSeeIn('[data-connection-test-result]', '(v4.0.1)');
});

test('a failed connection test says so', function (): void {
    $serviceConnection = connectionFormSonarr();
    connectionFormFakeSonarr(statusCode: 500);

    visit(route('admin.connections.edit', $serviceConnection, absolute: false))
        ->assertNoSmoke()
        ->fill('api_key', 'test')
        ->click('[data-connection-test]')
        ->assertSeeIn('[data-connection-test-result]', 'Connection failed.');
});

test('a generated webhook token can be revealed and is saved', function (): void {
    $serviceConnection = connectionFormSonarr();
    connectionFormFakeSonarr();

    $webpage = visit(route('admin.connections.edit', $serviceConnection, absolute: false))
        ->assertNoSmoke()
        ->assertScript("document.querySelector('[data-webhook-token-toggle]').disabled", true)
        ->assertScript("document.querySelector('[data-webhook-token-copy]').disabled", true)
        ->click('[data-webhook-token-generate]')
        ->assertScript("document.querySelector('#webhook_token').value.length", 64)
        ->click('[data-webhook-token-toggle]')
        ->assertAttribute('#webhook_token', 'type', 'text');

    $token = $webpage->script("document.querySelector('#webhook_token').value");

    $webpage->click('Update Connection')
        ->assertSee('Connection updated.');

    expect($serviceConnection->fresh()->webhook_token)->toBe($token);
});

test('a sabnzbd connection edits its hidden categories and shows its notification script', function (): void {
    $serviceConnection = ServiceConnection::factory()->sabnzbd()->create([
        'url' => 'http://sabnzbd.local:8080',
        'settings' => ['hidden_categories' => ['adult']],
    ]);

    visit(route('admin.connections.edit', $serviceConnection, absolute: false))
        ->assertNoSmoke()
        ->assertValue('#hidden_categories', 'adult')
        ->assertSeeIn('[data-sab-script] label', 'Notification script')
        ->assertSeeIn('[data-sab-script]', 'MediaManager SABnzbd notification script')
        ->assertMissing('[data-webhook-configure]')
        ->assertMissing('[data-disk-display]')
        ->assertMissing('[data-sonarr-library-types]')
        ->assertMissing('[data-subtitle-check-tags]')
        ->fill('#hidden_categories', 'adult, private')
        ->click('Update Connection')
        ->assertSee('Connection updated.');

    expect($serviceConnection->fresh()->settings['hidden_categories'])->toBe(['adult', 'private']);
});

test('a prowlarr connection lists its indexers and tests one', function (): void {
    $serviceConnection = ServiceConnection::factory()->prowlarr()->create([
        'url' => 'http://prowlarr.local:9696',
        'api_key' => 'test',
    ]);
    Http::fake([
        'prowlarr.local:9696/api/v1/indexer/7/test' => Http::response([]),
        'prowlarr.local:9696/api/v1/indexer' => Http::response([
            ['id' => 7, 'name' => 'NZBgeek', 'enable' => true, 'priority' => 25, 'implementation' => 'Newznab'],
        ]),
    ]);

    visit(route('admin.connections.edit', $serviceConnection, absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-prowlarr-indexers]', 'Configured Indexers')
        ->assertSeeIn('[data-prowlarr-indexers]', 'NZBgeek')
        ->assertSeeIn('[data-prowlarr-indexers]', 'Newznab')
        ->assertSeeIn('[data-prowlarr-indexers] tbody', 'Enabled')
        ->click('[data-prowlarr-indexer-test="7"]')
        ->assertSee('Indexer #7 tested OK.');
});

test('a whisparr connection shows its version select', function (): void {
    $serviceConnection = ServiceConnection::factory()->whisparr()->create(['url' => 'http://whisparr.local:6969']);

    visit(route('admin.connections.edit', $serviceConnection, absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-whisparr-version]', 'Whisparr Version')
        ->assertSeeIn('[data-whisparr-version] [data-slot="select-value"]', 'v3 (movie-based)');
});

/*
 * Loading is the disk picker's, the Sonarr root folders' and the Prowlarr
 * indexers' actual first paint: all three are Inertia::defer()'d, and like
 * the subtitle-check tag picker (ConnectionSubtitleCheckTagsTest), a visit()
 * always lands after the deferred partial reload resolves — Pest waits for
 * network idle, so delaying the faked upstream response only delays visit()
 * with it. The server-rendered HTML is that same first paint without the
 * race, so these assert on it directly via a plain GET. No Http::fake is set
 * up on purpose: nothing resolves a deferred prop during a synchronous GET,
 * so these requests reach no arr/Prowlarr instance at all.
 */
test("the sonarr edit form's server-rendered first paint shows the disk and root-folder sections loading, not failed", function (): void {
    $serviceConnection = connectionFormSonarr(['disk' => ['mode' => 'selected', 'paths' => [], 'display' => []]]);

    $html = (string) $this->get(route('admin.connections.edit', $serviceConnection))->getContent();

    expect($html)->toContain('data-server-rendered')
        ->and($html)->toContain('Loading disk paths from the service')
        ->and($html)->toContain('Loading root folders from Sonarr')
        ->and($html)->not->toContain('No disk paths reported.')
        ->and($html)->not->toContain('No root folders could be imported.');
});

test("the prowlarr edit form's server-rendered first paint shows the indexer table loading, not failed", function (): void {
    $serviceConnection = ServiceConnection::factory()->prowlarr()->create(['url' => 'http://prowlarr.local:9696']);

    $html = (string) $this->get(route('admin.connections.edit', $serviceConnection))->getContent();

    expect($html)->toContain('data-server-rendered')
        ->and($html)->toContain('Loading indexers')
        ->and($html)->not->toContain('No indexers loaded.');
});

test('a disk display picker with no reported paths says so instead of hanging on loading', function (): void {
    $serviceConnection = connectionFormSonarr(['disk' => ['mode' => 'selected', 'paths' => [], 'display' => []]]);
    connectionFormFakeSonarr(diskSpace: []);

    visit(route('admin.connections.edit', $serviceConnection, absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-disk-display]', 'No disk paths reported.')
        ->assertMissing('[data-disk-path="/tv"]');
});

test('a sonarr connection with no importable root folders says so instead of hanging on loading', function (): void {
    $serviceConnection = connectionFormSonarr();
    connectionFormFakeSonarr(rootFolders: []);

    visit(route('admin.connections.edit', $serviceConnection, absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-sonarr-library-types]', 'No root folders could be imported.');
});

test('a prowlarr connection with no indexers says so instead of hanging on loading', function (): void {
    $serviceConnection = ServiceConnection::factory()->prowlarr()->create(['url' => 'http://prowlarr.local:9696']);
    Http::fake([
        'prowlarr.local:9696/api/v1/indexer' => Http::response([]),
    ]);

    visit(route('admin.connections.edit', $serviceConnection, absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-prowlarr-indexers]', 'No indexers loaded.');
});

test('the create form takes its placeholders from the picked service type', function (): void {
    visit(route('admin.connections.create', absolute: false))
        ->assertNoSmoke()
        ->assertAttribute('#name', 'placeholder', 'Display name')
        ->assertAttribute('#url', 'placeholder', 'http://service.local:port')
        ->assertAttribute('#api_key', 'placeholder', 'Enter API key')
        ->assertScript("document.querySelector('#api_key').hasAttribute('autocomplete')", false)
        ->assertAttribute('#webhook_token', 'placeholder', 'Token for webhook authentication')
        ->assertButtonDisabled('Test Connection')
        ->assertMissing('[data-whisparr-version]')
        ->assertMissing('[data-webhook-url]')
        ->click('#service_type')
        ->click('[role="option"][aria-label="Sonarr"]')
        ->assertAttribute('#name', 'placeholder', 'My Sonarr')
        ->assertAttribute('#url', 'placeholder', 'http://sonarr.local:8989')
        ->assertAttribute('#api_key', 'placeholder', 'Sonarr API key (Settings → General)')
        ->click('#service_type')
        ->click('[role="option"][aria-label="Whisparr"]')
        ->assertSeeIn('[data-whisparr-version] [data-slot="select-value"]', 'v3 (movie-based)');
});

test('an admin creates a sonarr connection after a successful test', function (): void {
    Queue::fake();
    connectionFormFakeSonarr();

    $webpage = visit(route('admin.connections.create', absolute: false))
        ->assertNoSmoke()
        ->click('#service_type')
        ->click('[role="option"][aria-label="Sonarr"]')
        ->fill('name', 'New Sonarr')
        ->fill('url', 'http://sonarr.local:8989')
        ->fill('api_key', 'test')
        ->click('[data-connection-test]')
        ->assertSeeIn('[data-connection-test-result]', '(v4.0.1)')
        ->click('[data-webhook-token-generate]');

    $token = $webpage->script("document.querySelector('#webhook_token').value");

    $webpage->click('Create Connection')
        ->assertSee('Connection created.');

    $serviceConnection = ServiceConnection::query()->where('name', 'New Sonarr')->sole();

    expect($serviceConnection->url)->toBe('http://sonarr.local:8989')
        ->and($serviceConnection->api_key)->toBe('test')
        ->and($serviceConnection->webhook_token)->toBe($token);
});

test('a bazarr mapping survives switching the service type away and back', function (): void {
    ServiceConnection::factory()->sonarr()->create(['name' => 'Main Sonarr']);

    visit(route('admin.connections.create', absolute: false))
        ->assertNoSmoke()
        ->click('#service_type')
        ->click('[role="option"][aria-label="Bazarr"]')
        ->click('#sonarr_connection_id')
        ->click('[role="option"][aria-label="Use Main Sonarr as Sonarr connection"]')
        ->click('#service_type')
        ->click('[role="option"][aria-label="Sonarr"]')
        ->assertMissing('[data-bazarr-mappings]')
        ->click('#service_type')
        ->click('[role="option"][aria-label="Bazarr"]')
        ->assertSeeIn('#sonarr_connection_id', 'Main Sonarr');
});
