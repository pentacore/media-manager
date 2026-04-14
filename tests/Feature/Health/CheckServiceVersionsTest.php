<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('fetches latest release from GitHub for sonarr/radarr/jellyseerr', function (): void {
    $sonarr = ServiceConnection::factory()->sonarr()->create();
    $radarr = ServiceConnection::factory()->radarr()->create();
    $jellyseerr = ServiceConnection::factory()->jellyseerr()->create();

    Http::fake([
        'api.github.com/repos/Sonarr/Sonarr/releases/latest' => Http::response(['tag_name' => 'v4.0.5.1710']),
        'api.github.com/repos/Radarr/Radarr/releases/latest' => Http::response(['tag_name' => 'v5.3.6.8612']),
        'api.github.com/repos/Fallenbagel/jellyseerr/releases/latest' => Http::response(['tag_name' => 'v2.0.0']),
    ]);

    $this->artisan('services:check-versions')->assertSuccessful();

    expect($sonarr->fresh()->latest_version)->toBe('4.0.5.1710');
    expect($radarr->fresh()->latest_version)->toBe('5.3.6.8612');
    expect($jellyseerr->fresh()->latest_version)->toBe('2.0.0');
});

test('skips emby (closed-source)', function (): void {
    $emby = ServiceConnection::factory()->emby()->create();

    // No Http::fake needed — emby should be skipped without any HTTP call
    $this->artisan('services:check-versions')->assertSuccessful();

    expect($emby->fresh()->latest_version)->toBeNull();
});

test('handles GitHub API failure gracefully', function (): void {
    $sonarr = ServiceConnection::factory()->sonarr()->create();

    Http::fake(['api.github.com/*' => Http::response('Service Unavailable', 503)]);

    $this->artisan('services:check-versions')->assertSuccessful();

    expect($sonarr->fresh()->latest_version)->toBeNull();
});

test('skips inactive connections', function (): void {
    $sonarr = ServiceConnection::factory()->sonarr()->inactive()->create();

    $this->artisan('services:check-versions')->assertSuccessful();

    expect($sonarr->fresh()->latest_version)->toBeNull();
});

test('strips v prefix from tag name', function (): void {
    $sonarr = ServiceConnection::factory()->sonarr()->create();

    Http::fake([
        'api.github.com/*' => Http::response(['tag_name' => 'v1.2.3']),
    ]);

    $this->artisan('services:check-versions')->assertSuccessful();

    expect($sonarr->fresh()->latest_version)->toBe('1.2.3');
});
