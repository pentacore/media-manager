<?php

declare(strict_types=1);

use App\Jobs\ProcessWebhookEvent;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Sonarr\SonarrWebhookHandler;
use App\Services\Webhook\WebhookHandler;
use App\Settings\WebhookSettings;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
});

test('capture is enabled by default', function (): void {
    expect(resolve(WebhookSettings::class)->captureEnabled())->toBeTrue();
});

test('capture setting can be toggled and persists', function (): void {
    $webhookSettings = resolve(WebhookSettings::class);
    $webhookSettings->setCaptureEnabled(false);

    expect(resolve(WebhookSettings::class)->captureEnabled())->toBeFalse();
});

test('processing keeps the event row when capture is enabled', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create();
    $event = WebhookEvent::factory()->create(['service_connection_id' => $connection->id]);

    $mock = Mockery::mock(WebhookHandler::class);
    $mock->shouldReceive('handle')->once()->with($event);
    $this->app->bind(SonarrWebhookHandler::class, fn (): WebhookHandler => $mock);

    new ProcessWebhookEvent($event)->handle();

    expect(WebhookEvent::find($event->id))->not->toBeNull();
});

test('processing discards the event row when capture is disabled', function (): void {
    resolve(WebhookSettings::class)->setCaptureEnabled(false);

    $connection = ServiceConnection::factory()->sonarr()->create();
    $event = WebhookEvent::factory()->create(['service_connection_id' => $connection->id]);

    $mock = Mockery::mock(WebhookHandler::class);
    $mock->shouldReceive('handle')->once()->with($event);
    $this->app->bind(SonarrWebhookHandler::class, fn (): WebhookHandler => $mock);

    new ProcessWebhookEvent($event)->handle();

    expect(WebhookEvent::find($event->id))->toBeNull();
});

test('admin can toggle capture from the webhook log page', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.webhook-log.update-settings'), ['capture_enabled' => false])
        ->assertRedirect();

    expect(resolve(WebhookSettings::class)->captureEnabled())->toBeFalse();
});

test('non-admin cannot toggle capture', function (): void {
    $user = User::factory()->member()->create();

    $this->actingAs($user)
        ->put(route('admin.webhook-log.update-settings'), ['capture_enabled' => false])
        ->assertForbidden();
});

test('toggle endpoint validates capture_enabled', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.webhook-log.update-settings'), [])
        ->assertSessionHasErrors('capture_enabled');
});

test('webhook log index exposes capture setting to the page', function (): void {
    $admin = User::factory()->admin()->create();
    resolve(WebhookSettings::class)->setCaptureEnabled(false);

    $this->actingAs($admin)
        ->get(route('admin.webhook-log.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/WebhookLog/Index')
            ->where('settings.capture_enabled', false));
});
