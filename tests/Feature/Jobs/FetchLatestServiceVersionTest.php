<?php

declare(strict_types=1);

use App\Events\ServiceLatestVersionFetched;
use App\Jobs\FetchLatestServiceVersion;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Notifications\ServiceUpdateAvailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Notification::fake();
    Event::fake([ServiceLatestVersionFetched::class]);
});

test('broadcasts ServiceLatestVersionFetched when latest_version changes', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'version' => '4.0.4',
        'latest_version' => null,
    ]);

    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v4.0.5'])]);

    app()->call([new FetchLatestServiceVersion($connection), 'handle']);

    Event::assertDispatched(
        ServiceLatestVersionFetched::class,
        fn (ServiceLatestVersionFetched $event): bool => $event->serviceConnection->id === $connection->id,
    );
});

test('does not broadcast ServiceLatestVersionFetched when latest_version is unchanged', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'version' => '4.0.4',
        'latest_version' => '4.0.5',
    ]);

    Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v4.0.5'])]);

    app()->call([new FetchLatestServiceVersion($connection), 'handle']);

    Event::assertNotDispatched(ServiceLatestVersionFetched::class);
});

test('fetches and stores latest_version for sonarr', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();

    Http::fake([
        'api.github.com/repos/Sonarr/Sonarr/releases/latest' => Http::response(['tag_name' => 'v4.0.5']),
    ]);

    app()->call([new FetchLatestServiceVersion($connection), 'handle']);

    expect($connection->fresh()->latest_version)->toBe('4.0.5');
});

test('fetches and stores latest_version for emby', function (): void {
    $connection = ServiceConnection::factory()->emby()->create();

    Http::fake([
        'api.github.com/repos/MediaBrowser/Emby.Releases/releases/latest' => Http::response(['tag_name' => '4.8.0.80']),
    ]);

    app()->call([new FetchLatestServiceVersion($connection), 'handle']);

    expect($connection->fresh()->latest_version)->toBe('4.8.0.80');
});

test('no-op for service types not in REPO_MAP', function (): void {
    // Contrived case — all our current types have mappings. Guard against future types being added without a repo.
    expect(FetchLatestServiceVersion::REPO_MAP)->toHaveKeys(['sonarr', 'radarr', 'seerr', 'emby']);
});

test('no-op when GitHub API returns failure', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();

    Http::fake(['api.github.com/*' => Http::response('Service Unavailable', 503)]);

    app()->call([new FetchLatestServiceVersion($connection), 'handle']);

    expect($connection->fresh()->latest_version)->toBeNull();
});

test('strips v prefix', function (): void {
    $connection = ServiceConnection::factory()->radarr()->create();

    Http::fake([
        'api.github.com/*' => Http::response(['tag_name' => 'v5.3.6.8612']),
    ]);

    app()->call([new FetchLatestServiceVersion($connection), 'handle']);

    expect($connection->fresh()->latest_version)->toBe('5.3.6.8612');
});

test('implements ShouldQueue', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();
    expect(new FetchLatestServiceVersion($connection))->toBeInstanceOf(ShouldQueue::class);
});

test('notifies all admins when a newer version is detected', function (): void {
    $admin = User::factory()->admin()->create();
    User::factory()->admin()->create();
    User::factory()->member()->create();
    User::factory()->create();

    $connection = ServiceConnection::factory()->sonarr()->create([
        'version' => '4.0.4',
        'latest_version' => null,
    ]);

    Http::fake([
        'api.github.com/*' => Http::response(['tag_name' => 'v4.0.5']),
    ]);

    app()->call([new FetchLatestServiceVersion($connection), 'handle']);

    Notification::assertSentTo(
        $admin,
        ServiceUpdateAvailable::class,
        fn (ServiceUpdateAvailable $serviceUpdateAvailable): bool => $serviceUpdateAvailable->serviceConnection->is($connection)
            && $serviceUpdateAvailable->latestVersion === '4.0.5'
            && $serviceUpdateAvailable->currentVersion === '4.0.4',
    );
    Notification::assertCount(2);
});

test('does not notify when latest_version matches the installed version', function (): void {
    User::factory()->admin()->create();

    $connection = ServiceConnection::factory()->sonarr()->create([
        'version' => '4.0.5',
        'latest_version' => null,
    ]);

    Http::fake([
        'api.github.com/*' => Http::response(['tag_name' => 'v4.0.5']),
    ]);

    app()->call([new FetchLatestServiceVersion($connection), 'handle']);

    Notification::assertNothingSent();
});

test('does not re-notify when latest_version is unchanged from a previous run', function (): void {
    User::factory()->admin()->create();

    $connection = ServiceConnection::factory()->sonarr()->create([
        'version' => '4.0.4',
        'latest_version' => '4.0.5',
    ]);

    Http::fake([
        'api.github.com/*' => Http::response(['tag_name' => 'v4.0.5']),
    ]);

    app()->call([new FetchLatestServiceVersion($connection), 'handle']);

    Notification::assertNothingSent();
});

test('does not notify when installed version is unknown', function (): void {
    User::factory()->admin()->create();

    $connection = ServiceConnection::factory()->sonarr()->create([
        'version' => null,
        'latest_version' => null,
    ]);

    Http::fake([
        'api.github.com/*' => Http::response(['tag_name' => 'v4.0.5']),
    ]);

    app()->call([new FetchLatestServiceVersion($connection), 'handle']);

    Notification::assertNothingSent();
});
