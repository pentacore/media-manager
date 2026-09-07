<?php

declare(strict_types=1);

use App\Enums\NotificationSeverity;
use App\Models\NotificationDestination;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.testing.ensure_pages_exist', false);
    config()->set('services.telegram.token', '123:abc');
    config()->set('services.ntfy.server', 'https://ntfy.example.com');
});

test('non-admins cannot open the destinations page', function (): void {
    // Guest first: actingAs stays authenticated for the rest of the test.
    $this->get(route('admin.notification-destinations.index'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->member()->create())->get(route('admin.notification-destinations.index'))->assertForbidden();
});

test('index lists destinations with masked config and the available channels', function (): void {
    NotificationDestination::factory()->webhook()->create(['label' => 'Ops hooks', 'config' => ['url' => 'https://hooks.example.com/abcd1234', 'secret' => 'x']]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.notification-destinations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/NotificationDestinations/Index')
            ->has('destinations', 1)
            ->where('destinations.0.label', 'Ops hooks')
            ->where('destinations.0.channel', 'webhook')
            ->where('destinations.0.config_hint', '…1234')
            ->missing('destinations.0.config')
            ->where('channels.0.value', 'ntfy')
            ->where('severities.1.value', 'warning')
        );
});

test('index hides ntfy and telegram channel options when they are not configured', function (): void {
    config()->set('services.telegram.token', null);
    config()->set('services.ntfy.server', '');

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.notification-destinations.index'))
        ->assertInertia(fn ($page) => $page->where('channels', [
            ['value' => 'discord', 'label' => 'Discord'],
            ['value' => 'webhook', 'label' => 'Webhook'],
        ]));
});

test('admin can store a discord destination', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.notification-destinations.store'), [
            'channel' => 'discord',
            'label' => 'Ops channel',
            'is_enabled' => '1',
            'min_severity' => 'warning',
            'config' => ['url' => 'https://discord.com/api/webhooks/1/abc'],
        ])
        ->assertRedirect(route('admin.notification-destinations.index'))
        ->assertSessionHasNoErrors();

    $destination = NotificationDestination::query()->firstOrFail();
    expect($destination->label)->toBe('Ops channel')
        ->and($destination->min_severity)->toBe(NotificationSeverity::Warning)
        ->and($destination->config)->toBe(['url' => 'https://discord.com/api/webhooks/1/abc']);
});

test('store validates config per channel', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('admin.notification-destinations.store'), ['channel' => 'discord', 'label' => 'x', 'min_severity' => 'info', 'config' => ['url' => 'https://example.com']])
        ->assertSessionHasErrors('config.url');
    $this->actingAs($admin)->post(route('admin.notification-destinations.store'), ['channel' => 'telegram', 'label' => 'x', 'min_severity' => 'info', 'config' => ['chat_id' => 'abc']])
        ->assertSessionHasErrors('config.chat_id');
    $this->actingAs($admin)->post(route('admin.notification-destinations.store'), ['channel' => 'ntfy', 'label' => 'x', 'min_severity' => 'info', 'config' => ['topic' => 'bad topic']])
        ->assertSessionHasErrors('config.topic');
    $this->actingAs($admin)->post(route('admin.notification-destinations.store'), ['channel' => 'webhook', 'label' => 'x', 'min_severity' => 'info', 'config' => []])
        ->assertSessionHasErrors('config.url');
});

test('update keeps encrypted config values that are submitted blank', function (): void {
    $destination = NotificationDestination::factory()->webhook('keep-me')->create();

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.notification-destinations.update', $destination), [
            'channel' => 'webhook',
            'label' => 'Renamed',
            'is_enabled' => '0',
            'min_severity' => 'error',
            'config' => ['url' => 'https://hooks.example.com/new', 'secret' => ''],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $destination->refresh();
    expect($destination->label)->toBe('Renamed')
        ->and($destination->is_enabled)->toBeFalse()
        ->and($destination->config)->toBe(['url' => 'https://hooks.example.com/new', 'secret' => 'keep-me']);
});

test('admin can delete a destination', function (): void {
    $destination = NotificationDestination::factory()->discord()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->delete(route('admin.notification-destinations.destroy', $destination))
        ->assertRedirect();

    expect(NotificationDestination::query()->count())->toBe(0);
});

test('test action delivers through the destination channel and reports failures', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'discord.com/*' => Http::response('', 204),
        'hooks.example.com/*' => Http::response('nope', 500),
    ]);
    $admin = User::factory()->admin()->create();
    $discord = NotificationDestination::factory()->discord()->create();
    $webhook = NotificationDestination::factory()->webhook()->create();

    $this->actingAs($admin)->post(route('admin.notification-destinations.test', $discord))->assertRedirect()->assertSessionHasNoErrors();
    Http::assertSent(fn ($request): bool => $request['embeds'][0]['title'] === 'MediaManager test notification');

    $this->actingAs($admin)->post(route('admin.notification-destinations.test', $webhook))->assertSessionHasErrors('test');
});
