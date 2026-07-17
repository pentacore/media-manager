<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    config()->set('mediamanager.cache.store', 'array');
    config()->set('inertia.ssr.enabled', false);
    config()->set('inertia.testing.ensure_pages_exist', false);
    Http::preventStrayRequests();
});

test('only administrators can view and update Bazarr administration', function (): void {
    $bazarr = ServiceConnection::factory()->bazarr()->create();

    $this->get(route('bazarr.admin.index'))->assertRedirect(route('login'));

    foreach ([User::factory()->create(), User::factory()->member()->create()] as $user) {
        $this->actingAs($user)
            ->get(route('bazarr.admin.index', ['connection' => $bazarr->id]))
            ->assertForbidden();
        $this->actingAs($user)
            ->put(route('bazarr.admin.update'), ['connection' => $bazarr->id, 'settings' => []])
            ->assertForbidden();
    }
});

test('admin page exposes safe settings and a separately generated Bazarr link', function (): void {
    $bazarr = ServiceConnection::factory()->bazarr()->create([
        'url' => 'http://bazarr.test',
        'api_key' => 'connection-secret',
    ]);
    fakeAdminSettingsApi();

    $response = $this->actingAs(User::factory()->admin()->create())
        ->get(route('bazarr.admin.index', ['connection' => $bazarr->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $assertableInertia): AssertableInertia => $assertableInertia
            ->component('Bazarr/Admin')
            ->where('settings_writable', true)
            ->where('bazarr_external_url', 'http://bazarr.test')
            ->where('settings.scheduler.enabled', true)
            ->where('settings.language_profiles.0.name', 'English')
        );

    $props = $response->viewData('page')['props'];
    $settingsJson = json_encode($props['settings'], JSON_THROW_ON_ERROR);

    expect($settingsJson)->not->toContain('connection-secret')
        ->not->toContain('provider-password')
        ->not->toContain('hooks.example.test');
});

test('admin updates settings and records only changed allowlisted keys', function (): void {
    $bazarr = ServiceConnection::factory()->bazarr()->create([
        'url' => 'http://bazarr.test',
        'api_key' => 'connection-secret',
    ]);
    $admin = User::factory()->admin()->create();
    fakeAdminSettingsApi();

    $this->actingAs($admin)
        ->from(route('bazarr.admin.index', ['connection' => $bazarr->id]))
        ->put(route('bazarr.admin.update'), [
            'connection' => $bazarr->id,
            'settings' => [
                'scheduler_enabled' => false,
                'scheduler_interval_hours' => 8,
            ],
        ])
        ->assertRedirect();

    $activityLog = ActivityLog::query()->where('action', 'bazarr.settings.updated')->sole();

    expect($activityLog->user_id)->toBe($admin->id)
        ->and($activityLog->service_connection_id)->toBe($bazarr->id)
        ->and($activityLog->metadata)->toBe([
            'changed_keys' => ['scheduler_enabled', 'scheduler_interval_hours'],
        ])
        ->and(json_encode($activityLog->toArray(), JSON_THROW_ON_ERROR))->not->toContain('connection-secret');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/system/settings'));
});

test('admin settings reject unknown keys', function (): void {
    $bazarr = ServiceConnection::factory()->bazarr()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('bazarr.admin.update'), [
            'connection' => $bazarr->id,
            'settings' => ['api_key' => 'not-allowed'],
        ])
        ->assertSessionHasErrors('settings');

    expect(ActivityLog::query()->where('action', 'bazarr.settings.updated')->count())->toBe(0);
});

function fakeAdminSettingsApi(): void
{
    Http::fake([
        'bazarr.test/api/system/settings' => Http::sequence()
            ->push(['data' => [
                'scheduler' => ['enabled' => true, 'interval_hours' => 6],
                'subtitle_tools' => [
                    'automatic_subtitle_synchronization' => true,
                    'use_postprocessing' => false,
                ],
                'sonarr' => ['apikey' => 'sonarr-secret'],
            ]])
            ->push([], 204),
        'bazarr.test/api/system/languages/profiles' => Http::response([[
            'profileId' => 1,
            'name' => 'English',
            'items' => [['language' => 'eng']],
        ]]),
        'bazarr.test/api/system/tasks' => Http::response(['data' => []]),
        'bazarr.test/api/providers' => Http::response(['data' => [[
            'name' => 'OpenSubtitles',
            'status' => 'healthy',
            'password' => 'provider-password',
        ]]]),
        'bazarr.test/api/system/notifications' => Http::response(['data' => [[
            'id' => 1,
            'name' => 'Discord',
            'enabled' => true,
            'url' => 'https://hooks.example.test/private',
        ]]]),
        'bazarr.test/api/swagger.json' => Http::response([
            'swagger' => '2.0',
            'basePath' => '/api',
            'info' => ['title' => 'Bazarr', 'version' => '1.6.0'],
            'paths' => [
                '/system/settings' => [
                    'get' => ['responses' => ['200' => ['description' => 'OK']]],
                    'post' => ['responses' => ['204' => ['description' => 'OK']]],
                ],
            ],
        ]),
    ]);
}
