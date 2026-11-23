<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    config()->set('inertia.ssr.enabled', false);

    Cache::flush();
    Http::preventStrayRequests();
    Http::fake(['sonarr.local:8989/*' => Http::response([])]);

    $this->admin = User::factory()->admin()->create();
});

/**
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function subtitleAuditSlotsPayload(ServiceConnection $serviceConnection, array $fields): array
{
    return [
        'type' => $serviceConnection->type->value,
        'name' => $serviceConnection->name,
        'url' => $serviceConnection->url,
        ...$fields,
    ];
}

test('the edit page exposes the stored search slots of a Sonarr connection', function (?array $settings, ?int $expected): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
        'settings' => $settings,
    ]);

    $this->actingAs($this->admin)
        ->get(route('admin.connections.edit', $connection))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Connections/Edit')
            ->where('connection.subtitle_audit_search_slots', $expected)
            ->where('defaultSubtitleAuditSearchSlots', 2));
})->with([
    'configured' => [['subtitle_audit' => ['search_slots' => 4]], 4],
    'unset' => [null, null],
]);

test('updating a Sonarr connection stores the search slots', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
        'settings' => ['subtitle_check_tags' => ['sub-check']],
    ]);

    $this->actingAs($this->admin)
        ->put(route('admin.connections.update', $connection), subtitleAuditSlotsPayload($connection, [
            // As a browser posts it.
            'subtitle_audit_search_slots' => '3',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $connection->refresh();

    expect($connection->settings['subtitle_audit'])->toBe(['search_slots' => 3])
        ->and($connection->settings['subtitle_check_tags'])->toBe(['sub-check'])
        ->and($connection->subtitleAuditSearchSlots())->toBe(3);
});

test('a blank search slot field clears the stored value back to the default', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
        'settings' => ['subtitle_audit' => ['search_slots' => 5], 'subtitle_check_tags' => ['sub-check']],
    ]);

    $this->actingAs($this->admin)
        ->put(route('admin.connections.update', $connection), subtitleAuditSlotsPayload($connection, [
            'subtitle_audit_search_slots' => '',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $connection->refresh();

    expect($connection->settings)->not->toHaveKey('subtitle_audit')
        ->and($connection->settings['subtitle_check_tags'])->toBe(['sub-check'])
        ->and($connection->subtitleAuditSearchSlots())->toBe(2);
});

test('omitting the search slot field leaves the stored value alone', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
        'settings' => ['subtitle_audit' => ['search_slots' => 5]],
    ]);

    $this->actingAs($this->admin)
        ->put(route('admin.connections.update', $connection), subtitleAuditSlotsPayload($connection, []))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($connection->refresh()->subtitleAuditSearchSlots())->toBe(5);
});

test('the search slots are validated', function (mixed $slots): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
    ]);

    $this->actingAs($this->admin)
        ->put(route('admin.connections.update', $connection), subtitleAuditSlotsPayload($connection, [
            'subtitle_audit_search_slots' => $slots,
        ]))
        ->assertSessionHasErrors('subtitle_audit_search_slots');
})->with([
    'zero' => [0],
    'above ten' => [11],
    'not a number' => ['many'],
]);

test('a non-Sonarr connection does not store the search slots', function (): void {
    $connection = ServiceConnection::factory()->radarr()->create([
        'url' => 'http://radarr.local:7878', 'api_key' => 'test', 'is_active' => true,
    ]);

    $this->actingAs($this->admin)
        ->put(route('admin.connections.update', $connection), subtitleAuditSlotsPayload($connection, [
            'subtitle_audit_search_slots' => 3,
        ]))
        ->assertRedirect();

    expect($connection->refresh()->settings ?? [])->not->toHaveKey('subtitle_audit');
});
