<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

test('fetches latest release from GitHub for sonarr/radarr/seerr', function (): void {
    $sonarr = ServiceConnection::factory()->sonarr()->create();
    $radarr = ServiceConnection::factory()->radarr()->create();
    $seerr = ServiceConnection::factory()->seerr()->create();

    Http::fake([
        'api.github.com/repos/Sonarr/Sonarr/releases/latest' => Http::response(['tag_name' => 'v4.0.5.1710']),
        'api.github.com/repos/Radarr/Radarr/releases/latest' => Http::response(['tag_name' => 'v5.3.6.8612']),
        'api.github.com/repos/seerr-team/seerr/releases/latest' => Http::response(['tag_name' => 'v2.0.0']),
    ]);

    $this->artisan('services:check-versions')->assertSuccessful();

    expect($sonarr->fresh()->latest_version)->toBe('4.0.5.1710');
    expect($radarr->fresh()->latest_version)->toBe('5.3.6.8612');
    expect($seerr->fresh()->latest_version)->toBe('2.0.0');
});

test('checks emby version via MediaBrowser/Emby.Releases mirror', function (): void {
    $emby = ServiceConnection::factory()->emby()->create();

    Http::fake([
        'api.github.com/repos/MediaBrowser/Emby.Releases/releases/latest' => Http::response(['tag_name' => '4.8.0.80']),
    ]);

    $this->artisan('services:check-versions')->assertSuccessful();

    expect($emby->fresh()->latest_version)->toBe('4.8.0.80');
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

test('attaches Authorization header when github token is configured', function (): void {
    config()->set('services.github.token', 'fake-token');

    ServiceConnection::factory()->sonarr()->create();

    Http::fake([
        'api.github.com/*' => Http::response(['tag_name' => 'v1.2.3']),
    ]);

    $this->artisan('services:check-versions')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer fake-token')
        && $request->hasHeader('X-GitHub-Api-Version', '2022-11-28'));
});

test('omits Authorization header when github token is empty', function (): void {
    config()->set('services.github.token');

    ServiceConnection::factory()->sonarr()->create();

    Http::fake([
        'api.github.com/*' => Http::response(['tag_name' => 'v1.2.3']),
    ]);

    $this->artisan('services:check-versions')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
});
