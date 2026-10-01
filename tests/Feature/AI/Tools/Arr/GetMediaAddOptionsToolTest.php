<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\Arr\GetMediaAddOptionsTool;
use App\Enums\WhisparrVersion;
use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * Fakes the quality profile and root folder endpoints of an Arr host with
 * full-sized payloads, so the tests can assert the tool trims them.
 */
function fakeMediaAddOptions(string $host): void
{
    Http::fake([
        $host.'/api/v3/qualityprofile' => Http::response([
            ['id' => 1, 'name' => 'Any', 'upgradeAllowed' => true, 'cutoff' => 4, 'items' => [['quality' => ['id' => 4]]]],
            ['id' => 6, 'name' => 'HD-1080p', 'upgradeAllowed' => false, 'cutoff' => 7, 'items' => []],
        ]),
        $host.'/api/v3/rootfolder' => Http::response([
            ['id' => 1, 'path' => '/media/library', 'accessible' => true, 'freeSpace' => 1_000_000, 'unmappedFolders' => [['name' => 'x']]],
        ]),
    ]);
}

test('returns the trimmed quality profiles and root folders of the requested service', function (string $service, string $host): void {
    $factory = ServiceConnection::factory()->{$service}();
    if ($service === 'whisparr') {
        $factory = $factory->whisparrVersion(WhisparrVersion::V3);
    }

    $factory->create(['url' => 'http://'.$host, 'api_key' => 'test', 'is_active' => true]);
    fakeMediaAddOptions($host);

    $result = json_decode((new GetMediaAddOptionsTool)->handle(new Request(['service' => $service])), true);

    expect($result)->toBe([
        'quality_profiles' => [
            ['id' => 1, 'name' => 'Any'],
            ['id' => 6, 'name' => 'HD-1080p'],
        ],
        'root_folders' => [
            ['path' => '/media/library', 'free_space' => 1_000_000],
        ],
    ]);
})->with([
    ['sonarr', 'sonarr.local:8989'],
    ['radarr', 'radarr.local:7878'],
    ['whisparr', 'whisparr.local:6969'],
]);

test('returns invalid_arguments for an unknown service', function (): void {
    $result = json_decode((new GetMediaAddOptionsTool)->handle(new Request(['service' => 'emby'])), true);

    expect($result['error'])->toBe('invalid_arguments');
    expect($result['errors'])->toHaveKey('service');
    Http::assertNothingSent();
});

test('returns tool_failed when the service has no active connection', function (): void {
    ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878', 'api_key' => 'test', 'is_active' => false,
    ]);

    $result = json_decode((new GetMediaAddOptionsTool)->handle(new Request(['service' => 'radarr'])), true);

    expect($result['error'])->toBe('tool_failed');
    Http::assertNothingSent();
});

test('risk is Read', function (): void {
    expect((new GetMediaAddOptionsTool)->risk())->toBe(Risk::Read);
});
