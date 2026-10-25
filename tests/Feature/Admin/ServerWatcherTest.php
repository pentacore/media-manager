<?php

declare(strict_types=1);

use App\Models\ActivityLog;
use App\Models\User;
use App\Settings\MonitoringSettings;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
    config()->set('mediamanager.monitoring.uptime_kuma_push_url');
});

test('an admin saves the push url and the audit row masks it', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->from('/')
        ->put(route('admin.servers.watcher.update'), ['push_url' => 'https://kuma.test/api/push/abc123'])
        ->assertRedirect('/')
        ->assertSessionHasNoErrors();

    expect(resolve(MonitoringSettings::class)->uptimeKumaPushUrl())->toBe('https://kuma.test/api/push/abc123');

    $activityLog = ActivityLog::query()->where('action', 'settings.updated')->where('subject_type', 'monitoring')->sole();

    expect($activityLog->metadata['changes']['monitoring.uptime_kuma_push_url'])->toBe(['changed' => true])
        ->and(json_encode($activityLog->metadata, JSON_THROW_ON_ERROR))->not->toContain('abc123');
});

test('a blank url keeps the stored one and clear removes it', function (): void {
    $admin = User::factory()->admin()->create();
    $monitoringSettings = resolve(MonitoringSettings::class);
    $monitoringSettings->setUptimeKumaPushUrl('https://kuma.test/api/push/abc123');

    $this->actingAs($admin)
        ->put(route('admin.servers.watcher.update'), ['push_url' => ''])
        ->assertSessionHasNoErrors();

    expect($monitoringSettings->uptimeKumaPushUrl())->toBe('https://kuma.test/api/push/abc123')
        ->and(ActivityLog::query()->where('action', 'settings.updated')->count())->toBe(0);

    $this->actingAs($admin)
        ->put(route('admin.servers.watcher.update'), ['clear' => true])
        ->assertSessionHasNoErrors();

    Cache::flush();

    expect($monitoringSettings->uptimeKumaPushUrl())->toBeNull();

    $activityLog = ActivityLog::query()->where('action', 'settings.updated')->where('subject_type', 'monitoring')->sole();

    expect(json_encode($activityLog->metadata, JSON_THROW_ON_ERROR))->not->toContain('abc123');
});

test('members cannot change the watcher', function (): void {
    $this->actingAs(User::factory()->member()->create())
        ->put(route('admin.servers.watcher.update'), ['push_url' => 'https://kuma.test/api/push/abc123'])
        ->assertForbidden();

    expect(resolve(MonitoringSettings::class)->uptimeKumaPushUrl())->toBeNull();
});

test('a non-url is rejected', function (string $value): void {
    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.servers.watcher.update'), ['push_url' => $value])
        ->assertSessionHasErrors('push_url');

    expect(resolve(MonitoringSettings::class)->uptimeKumaPushUrl())->toBeNull();
})->with(['not a url', 'ftp://kuma.test/push/abc123', 'https://kuma.test/'.str_repeat('a', 2040)]);

test('removing the url while it comes from the environment changes nothing and says where to remove it', function (): void {
    config()->set('mediamanager.monitoring.uptime_kuma_push_url', 'https://kuma.test/api/push/fromenv1');

    $this->actingAs(User::factory()->admin()->create())
        ->from(route('admin.servers.index'))
        ->put(route('admin.servers.watcher.update'), ['clear' => true])
        ->assertRedirect(route('admin.servers.index'))
        ->assertInertiaFlash('toast', ['type' => 'info', 'message' => 'The push URL is set by MEDIAMANAGER_UPTIME_KUMA_PUSH_URL in the environment. Remove it there.']);

    expect(resolve(MonitoringSettings::class)->uptimeKumaPushUrl())->toBe('https://kuma.test/api/push/fromenv1')
        ->and(ActivityLog::query()->where('action', 'settings.updated')->exists())->toBeFalse();
});

test('a saved url wins over the environment one and removing it falls back to it', function (): void {
    config()->set('mediamanager.monitoring.uptime_kuma_push_url', 'https://kuma.test/api/push/fromenv1');
    $monitoringSettings = resolve(MonitoringSettings::class);

    expect($monitoringSettings->pushUrlSource())->toBe(MonitoringSettings::SOURCE_ENVIRONMENT);

    $monitoringSettings->setUptimeKumaPushUrl('https://kuma.test/api/push/saved222');

    expect($monitoringSettings->uptimeKumaPushUrl())->toBe('https://kuma.test/api/push/saved222')
        ->and($monitoringSettings->pushUrlSource())->toBe(MonitoringSettings::SOURCE_SAVED);

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.servers.watcher.update'), ['clear' => true])
        ->assertSessionHasNoErrors();

    expect($monitoringSettings->uptimeKumaPushUrl())->toBe('https://kuma.test/api/push/fromenv1')
        ->and($monitoringSettings->pushUrlSource())->toBe(MonitoringSettings::SOURCE_ENVIRONMENT);
});
