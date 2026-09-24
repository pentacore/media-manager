<?php

declare(strict_types=1);

use App\Models\NotificationDestination;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('admin adds a discord destination and sees it listed with a masked target', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.notification-destinations.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-destination-empty]', 'No destinations yet.')
        ->click('[data-destination-add]')
        ->click('[data-destination-channel="create"]')
        ->click('Discord')
        ->fill('[name="label"]', 'Ops channel')
        ->fill('[name="config[url]"]', 'https://discord.com/api/webhooks/1/abcd9999')
        ->click('Save')
        ->assertSee('Notification destination added.')
        ->assertSeeIn('[data-destination-row]', 'Ops channel')
        ->assertSeeIn('[data-destination-row]', '…9999')
        ->assertDontSee('abcd9999');

    expect(NotificationDestination::query()->where('label', 'Ops channel')->exists())->toBeTrue();
});

test('admin disables a destination from the edit dialog', function (): void {
    $destination = NotificationDestination::factory()->webhook()->create(['label' => 'Hooks']);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.notification-destinations.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-destination-status]', 'Enabled')
        ->click('Edit')
        ->click('[data-destination-enabled="edit"]')
        ->click('Save')
        ->assertSee('Notification destination updated.')
        ->assertSeeIn('[data-destination-status]', 'Disabled');

    expect($destination->fresh()->is_enabled)->toBeFalse();
});

test('user saves a webhook url and can send a test through it', function (): void {
    Http::fake(['hooks.example.com/*' => Http::response('ok')]);
    $this->actingAs(User::factory()->create());

    visit(route('settings.notifications.edit', absolute: false))
        ->assertNoSmoke()
        ->fill('[name="webhook_url"]', 'https://hooks.example.com/mm')
        ->assertSee('Save before sending a test to a changed destination.')
        ->assertDisabled('[data-test-send="webhook"]')
        ->click('Save preferences')
        ->assertSee('Notification preferences saved.')
        ->assertDontSee('Save before sending a test to a changed destination.')
        ->assertEnabled('[data-test-send="webhook"]')
        ->click('[data-test-send="webhook"]')
        ->assertSee('Test notification sent.');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://hooks.example.com/mm');
});
