<?php

declare(strict_types=1);

use App\Models\AiModelPrice;
use App\Models\BazarrServiceLink;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Settings\MediaReplacementSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

dataset('member browser page routes', array_combine(
    browserSmokeMemberRouteNames(),
    browserSmokeMemberRouteNames(),
));

dataset('admin browser page routes', array_combine(
    browserSmokeAdminRouteNames(),
    browserSmokeAdminRouteNames(),
));

test('member page renders without browser errors', function (string $routeName): void {
    prepareBrowserSmokeServiceConnections();

    $this->actingAs(User::factory()->member()->create());

    $path = route($routeName, absolute: false);

    visit($path)
        ->assertPathIs($path)
        ->assertNoSmoke();
})->with('member browser page routes');

test('admin page renders without browser errors', function (string $routeName): void {
    config()->set('mediamanager.ai.enabled', true);

    $this->actingAs(User::factory()->admin()->create());

    $path = route($routeName, absolute: false);

    visit($path)
        ->assertPathIs($path)
        ->assertNoSmoke();
})->with('admin browser page routes');

test('admin can edit and save media replacement configuration without browser errors', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    AiModelPrice::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-5-mini',
    ]);

    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit('/admin/ai-settings')
        ->assertSee('Media replacement')
        ->assertSee('Preferred subtitle languages')
        ->assertSee('Approval required')
        ->check('Enable automatic candidate selection')
        ->fill('input[placeholder="English, Swedish"]', 'English, Swedish')
        ->assertScript(
            'JSON.parse(document.querySelector(\'input[name="media_replacement"]\').value).automatic_selection_enabled === true',
        )
        ->assertScript(
            'JSON.parse(document.querySelector(\'input[name="media_replacement"]\').value).global_languages.join(\',\') === \'English,Swedish\'',
        )
        ->click('Save settings')
        ->assertSee('AI settings updated.');

    expect(resolve(MediaReplacementSettings::class)->configuration())
        ->automatic_selection_enabled->toBeTrue()
        ->global_languages->toBe(['eng', 'swe']);

    $webpage->assertNoSmoke();
});

test('admin can classify imported Sonarr root folders without browser errors', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'name' => 'Main Sonarr',
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'test',
    ]);
    Http::fake([
        'sonarr.local:8989/api/v3/rootfolder' => Http::response([
            ['id' => 1, 'path' => '/tv'],
            ['id' => 2, 'path' => '/anime'],
        ]),
    ]);

    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.connections.edit', $connection, absolute: false))
        ->assertSee('Sonarr library types')
        ->assertSee('/anime')
        ->assertSee('/tv')
        ->select('sonarr_root_scope_1', 'tv')
        ->select('sonarr_root_scope_2', 'anime')
        ->assertScript(
            'document.querySelector(\'#sonarr_root_scope_2\').value === "anime"',
        )
        ->click('Update Connection')
        ->assertSee('Connection updated.');

    expect($connection->refresh()->settings['sonarr_root_folders'])->toContainEqual([
        'root_folder_id' => 2,
        'path' => '/anime',
        'scope' => 'anime',
    ]);

    $webpage->assertNoSmoke();
});

test('admin can create a Bazarr mapping without browser errors', function (): void {
    Queue::fake();
    $sonarr = ServiceConnection::factory()->sonarr()->create(['name' => 'Main Sonarr']);
    $radarr = ServiceConnection::factory()->radarr()->create(['name' => 'Main Radarr']);

    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.connections.create', absolute: false))
        ->click('#service_type')
        ->click('[role="option"][aria-label="Bazarr"]')
        ->assertSee('Sonarr connection')
        ->assertSee('Radarr connection')
        ->fill('name', 'Main Bazarr')
        ->fill('url', 'http://bazarr.local:6767')
        ->fill('api_key', 'bazarr-api-key')
        ->fill('webhook_token', 'bazarr-webhook-token')
        ->click('#sonarr_connection_id')
        ->click('[role="option"][aria-label="Use Main Sonarr as Sonarr connection"]')
        ->click('#radarr_connection_id')
        ->click('[role="option"][aria-label="Use Main Radarr as Radarr connection"]')
        ->click('Create Connection')
        ->assertSee('Connection created.');

    $serviceConnection = ServiceConnection::query()->where('name', 'Main Bazarr')->sole();

    expect(BazarrServiceLink::query()
        ->where('bazarr_connection_id', $serviceConnection->id)
        ->where('related_connection_id', $sonarr->id)
        ->where('role', 'sonarr')
        ->exists())->toBeTrue()
        ->and(BazarrServiceLink::query()
            ->where('bazarr_connection_id', $serviceConnection->id)
            ->where('related_connection_id', $radarr->id)
            ->where('role', 'radarr')
            ->exists())->toBeTrue();

    $sonarr->update(['is_active' => false]);

    $webpage->navigate(route('admin.connections.edit', $serviceConnection, absolute: false))
        ->assertScript(
            'document.querySelector("#sonarr_connection_id").textContent.includes("Main Sonarr (inactive)")',
        )
        ->assertScript(
            'document.querySelector("#radarr_connection_id").textContent.includes("Main Radarr")',
        )
        ->click('#radarr_connection_id')
        ->click('[role="option"][aria-label="No Radarr connection"]')
        ->click('Update Connection')
        ->assertSee('Connection updated.');

    expect(BazarrServiceLink::query()
        ->where('bazarr_connection_id', $serviceConnection->id)
        ->where('role', 'sonarr')
        ->exists())->toBeTrue()
        ->and(BazarrServiceLink::query()
            ->where('bazarr_connection_id', $serviceConnection->id)
            ->where('role', 'radarr')
            ->doesntExist())->toBeTrue();

    $webpage->assertNoSmoke();
});

test('every static authenticated page route is classified for browser coverage', function (): void {
    $coveredRouteNames = collect([
        ...browserSmokeMemberRouteNames(),
        ...browserSmokeAdminRouteNames(),
    ]);
    $excludedRouteNames = collect(browserSmokeExcludedRouteNames());

    $staticAuthenticatedRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (Illuminate\Routing\Route $route): bool => in_array('GET', $route->methods(), true))
        ->filter(fn (Illuminate\Routing\Route $route): bool => in_array('auth', $route->gatherMiddleware(), true))
        ->reject(fn (Illuminate\Routing\Route $route): bool => str_contains($route->uri(), '{'));

    $namedRouteNames = $staticAuthenticatedRoutes
        ->map(fn (Illuminate\Routing\Route $route): ?string => $route->getName())
        ->filter()
        ->values();
    $unnamedRouteUris = $staticAuthenticatedRoutes
        ->filter(fn (Illuminate\Routing\Route $route): bool => $route->getName() === null)
        ->map(fn (Illuminate\Routing\Route $route): string => $route->uri())
        ->values()
        ->all();

    expect($namedRouteNames->diff($coveredRouteNames->merge($excludedRouteNames))->values()->all())->toBe([])
        ->and($coveredRouteNames->diff($namedRouteNames)->values()->all())->toBe([])
        ->and($excludedRouteNames->diff($namedRouteNames)->values()->all())->toBe([])
        ->and($unnamedRouteUris)->toBe(['settings']);
});

test('admin edit page for a Prowlarr connection renders without console errors', function (): void {
    $admin = User::factory()->admin()->create();
    $connection = ServiceConnection::factory()->prowlarr()->create([
        'url' => 'http://prowlarr.local:9696',
        'api_key' => 'test',
    ]);

    $this->actingAs($admin);

    visit(sprintf('/admin/connections/%d/edit', $connection->id))->assertNoSmoke();
});

test('viewer landing on dashboard sees no realtime auth errors in the console', function (): void {
    // Verifies the I1 fix: dashboard, activity, and emby.activity channels
    // are now open to all auth users, so a viewer's Echo subscriptions don't
    // 403 and surface as console errors.
    $viewer = User::factory()->create(); // default role is Viewer

    $this->actingAs($viewer);

    visit('/dashboard')
        ->assertNoSmoke()
        ->assertSee('Dashboard');
});

/**
 * @return list<string>
 */
function browserSmokeMemberRouteNames(): array
{
    return [
        'dashboard',
        'activity-log',
        'media.search.index',
        'media.series.index',
        'media.series.create',
        'media.movies.index',
        'media.movies.create',
        'media.requests.index',
        'media.anime.index',
        'media.library.activity.queue',
        'prowlarr.search',
        'sabnzbd.queue.index',
        'actions.requests.index',
        'statistics.index',
        'monitoring.now-playing',
        'monitoring.watch-history',
        'monitoring.service-health',
        'notifications.index',
        'profile.edit',
        'appearance.edit',
        'settings.preferences.edit',
        'settings.notifications.edit',
    ];
}

/**
 * @return list<string>
 */
function browserSmokeAdminRouteNames(): array
{
    return [
        'admin.connections.index',
        'admin.connections.create',
        'admin.users.index',
        'actions.rules.index',
        'emby.links.index',
        'admin.statistics.index',
        'admin.ai-settings.index',
        'admin.decision-agent.index',
        'admin.ai-usage.index',
        'admin.ai-prices.index',
        'admin.ai-conversations.index',
        'admin.webhook-log.index',
        'admin.jobs.index',
        'ai.chat',
    ];
}

/**
 * @return list<string>
 */
function browserSmokeExcludedRouteNames(): array
{
    return [
        'activity-log.export',
        'admin.ai-usage.export',
        'ai.chat.pending-workflow',
        'ai.conversations.index',
        'media.search.instant',
        'monitoring.watch-history.export',
        'security.edit',
    ];
}

function prepareBrowserSmokeServiceConnections(): void
{
    Queue::fake();
    Http::fake(['*' => Http::response([])]);

    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.test']);
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.test']);
    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.test']);
    ServiceConnection::factory()->prowlarr()->create(['url' => 'http://prowlarr.test']);
    ServiceConnection::factory()->sabnzbd()->create(['url' => 'http://sabnzbd.test']);
    ServiceConnection::factory()->emby()->create(['url' => 'http://emby.test']);
}
