<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Services\Bazarr\BazarrSettingsAdapter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('mediamanager.cache.store', 'array');
    Http::preventStrayRequests();
});

test('it projects only explicitly safe non-secret settings', function (): void {
    $connection = ServiceConnection::factory()->bazarr()->create([
        'url' => 'http://bazarr.test',
        'api_key' => 'connection-secret',
    ]);
    fakeSettingsApi();

    $settings = resolve(BazarrSettingsAdapter::class)->read($connection);

    expect(array_keys($settings))->toBe([
        'language_profiles',
        'profile_assignments',
        'tasks',
        'scheduler',
        'subtitle_tools',
        'provider_status',
        'notifications',
    ])->and(arrayKeysForBazarrSettings($settings))
        ->each->not->toMatch('/(?:password|token|api_?key|apikey|secret|username|url)/i')
        ->and(json_encode($settings, JSON_THROW_ON_ERROR))
        ->not->toContain('sonarr-secret')
        ->not->toContain('provider-password')
        ->not->toContain('https://hooks.example.test/private')
        ->and($settings['language_profiles'][0]['name'])->toBe('English')
        ->and($settings['provider_status'][0])->toBe([
            'name' => 'OpenSubtitles',
            'status' => 'healthy',
            'throttled_until' => null,
        ]);
});

test('it writes only allowlisted settings with exact Bazarr form fields', function (): void {
    $connection = ServiceConnection::factory()->bazarr()->create([
        'url' => 'http://bazarr.test',
        'api_key' => 'connection-secret',
    ]);
    fakeSettingsApi();

    $changed = resolve(BazarrSettingsAdapter::class)->update($connection, [
        'use_postprocessing' => true,
        'scheduler_interval_hours' => 12,
    ]);

    expect($changed)->toBe(['scheduler_interval_hours', 'use_postprocessing']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://bazarr.test/api/system/settings'
        && $request['settings-general-use_postprocessing'] === true
        && $request['settings-scheduler-interval'] === 12
        && count($request->data()) === 2);
});

test('it rejects unknown or invalid write values before an update', function (): void {
    $connection = ServiceConnection::factory()->bazarr()->create(['url' => 'http://bazarr.test']);
    fakeSettingsApi();
    $bazarrSettingsAdapter = resolve(BazarrSettingsAdapter::class);

    expect(fn () => $bazarrSettingsAdapter->update($connection, ['sonarr_api_key' => 'leak']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $bazarrSettingsAdapter->update($connection, ['scheduler_interval_hours' => 0]))
        ->toThrow(InvalidArgumentException::class);

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/system/settings'));
});

test('it blocks writes when the Bazarr settings adapter capability is unavailable', function (): void {
    $connection = ServiceConnection::factory()->bazarr()->create(['url' => 'http://bazarr.test']);
    fakeSettingsApi(settingsCapability: false);

    expect(fn () => resolve(BazarrSettingsAdapter::class)->update($connection, ['scheduler_enabled' => true]))
        ->toThrow(DomainException::class, 'does not support');
});

function fakeSettingsApi(bool $settingsCapability = true): void
{
    Http::fake([
        'bazarr.test/api/system/settings' => Http::sequence()
            ->push(['data' => [
                'sonarr' => ['apikey' => 'sonarr-secret', 'url' => 'http://sonarr'],
                'scheduler' => ['enabled' => true, 'interval_hours' => 6, 'secret' => 'hidden'],
                'subtitle_tools' => [
                    'automatic_subtitle_synchronization' => true,
                    'use_postprocessing' => false,
                ],
                'profile_assignments' => [['scope' => 'anime', 'profile_id' => 1, 'api_key' => 'hidden']],
            ]])
            ->push([], 204),
        'bazarr.test/api/system/languages/profiles' => Http::response([[
            'profileId' => 1,
            'name' => 'English',
            'cutoff' => 1,
            'items' => [['language' => 'eng', 'audio_exclude' => 'private']],
            'password' => 'hidden',
        ]]),
        'bazarr.test/api/system/tasks' => Http::response(['data' => [[
            'taskid' => 'search_missing',
            'name' => 'Search missing',
            'status' => 'idle',
            'token' => 'hidden',
        ]]]),
        'bazarr.test/api/providers' => Http::response(['data' => [[
            'name' => 'OpenSubtitles',
            'status' => 'healthy',
            'throttled_until' => null,
            'username' => 'provider-user',
            'password' => 'provider-password',
        ]]]),
        'bazarr.test/api/system/notifications' => Http::response(['data' => [[
            'id' => 2,
            'name' => 'Discord',
            'enabled' => true,
            'url' => 'https://hooks.example.test/private',
        ]]]),
        'bazarr.test/api/swagger.json' => Http::response([
            'swagger' => '2.0',
            'basePath' => '/api',
            'info' => ['title' => 'Bazarr', 'version' => '1.6.0'],
            'paths' => $settingsCapability ? [
                '/system/settings' => [
                    'get' => ['responses' => ['200' => ['description' => 'OK']]],
                    'post' => ['responses' => ['204' => ['description' => 'OK']]],
                ],
            ] : [
                '/unsupported' => [
                    'get' => ['responses' => ['200' => ['description' => 'OK']]],
                ],
            ],
        ]),
    ]);
}

/**
 * @param  array<string, mixed>  $values
 * @return list<string>
 */
function arrayKeysForBazarrSettings(array $values, string $prefix = ''): array
{
    $keys = [];

    foreach ($values as $key => $value) {
        $qualifiedKey = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        $keys[] = $qualifiedKey;

        if (is_array($value)) {
            $keys = [...$keys, ...arrayKeysForBazarrSettings($value, $qualifiedKey)];
        }
    }

    return $keys;
}
