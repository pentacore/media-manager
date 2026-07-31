<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    // Inertia's SSR gateway posts through the HTTP client, so leaving SSR on
    // under preventStrayRequests() turns every page render into a 500.
    config()->set('inertia.ssr.enabled', false);

    Cache::flush();
    Http::preventStrayRequests();

    $this->admin = User::factory()->create(['role' => UserRole::Admin]);
});

test('the edit page exposes the arr tags and the configured selection', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
        'settings' => ['subtitle_check_tags' => ['sub-check']],
    ]);

    // The junk rows are deliberate: the arr response is untrusted, and without
    // them a well-formed fixture would let the row filter be deleted unnoticed.
    Http::fake([
        'sonarr.local:8989/api/v3/tag' => Http::response([
            ['id' => 1, 'label' => 'sub-check'],
            'not-a-row',
            ['id' => null, 'label' => 'no-id'],
            ['id' => 3, 'label' => '   '],
            ['id' => 4],
            ['id' => 2, 'label' => 'anime'],
        ]),
        'sonarr.local:8989/api/v3/rootfolder' => Http::response([]),
    ]);

    $this->actingAs($this->admin)
        ->get(route('admin.connections.edit', $connection))
        ->assertInertia(fn ($page) => $page
            ->where('arrTags', [
                ['id' => 1, 'label' => 'sub-check'],
                ['id' => 2, 'label' => 'anime'],
            ])
            ->where('subtitleCheckTags', ['sub-check']));
});

test('the edit page reports tags as unavailable for a non-arr connection without calling out', function (): void {
    $connection = ServiceConnection::factory()->emby()->create([
        'url' => 'http://emby.local:8096', 'api_key' => 'test', 'is_active' => true,
    ]);

    // A catch-all 200 rather than no fake at all: arrTags() swallows Throwable,
    // so a stray-request exception would be indistinguishable from the guard
    // firing. An empty-but-successful response would surface as [], not null.
    Http::fake();

    $this->actingAs($this->admin)
        ->get(route('admin.connections.edit', $connection))
        ->assertInertia(fn ($page) => $page
            ->where('arrTags', null)
            ->where('subtitleCheckTags', []));

    Http::assertNothingSent();
});

test('the edit page reports tags as unavailable for an inactive arr connection', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => false,
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/tag' => Http::response([['id' => 1, 'label' => 'sub-check']]),
    ]);

    $this->actingAs($this->admin)
        ->get(route('admin.connections.edit', $connection))
        ->assertInertia(fn ($page) => $page->where('arrTags', null));

    Http::assertNothingSent();
});

test('updating a connection persists the selected tags', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
    ]);

    $this->actingAs($this->admin)
        ->put(route('admin.connections.update', $connection), [
            'type' => 'sonarr',
            'name' => $connection->name,
            'url' => 'http://sonarr.local:8989',
            'subtitle_check_tags' => ['Sub-Check', 'anime'],
        ])
        ->assertRedirect();

    expect($connection->fresh()->settings['subtitle_check_tags'])->toBe(['sub-check', 'anime']);
});

test('omitting the field leaves the stored tags untouched', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
        'settings' => ['subtitle_check_tags' => ['sub-check']],
    ]);

    $this->actingAs($this->admin)
        ->put(route('admin.connections.update', $connection), [
            'type' => 'sonarr',
            'name' => $connection->name,
            'url' => 'http://sonarr.local:8989',
        ])
        ->assertRedirect();

    expect($connection->fresh()->settings['subtitle_check_tags'])->toBe(['sub-check']);
});

test('a non-arr connection does not store the field', function (): void {
    $connection = ServiceConnection::factory()->emby()->create([
        'url' => 'http://emby.local:8096', 'api_key' => 'test', 'is_active' => true,
    ]);

    $this->actingAs($this->admin)
        ->put(route('admin.connections.update', $connection), [
            'type' => 'emby',
            'name' => $connection->name,
            'url' => 'http://emby.local:8096',
            'subtitle_check_tags' => ['sub-check'],
        ])
        ->assertRedirect();

    expect($connection->fresh()->settings ?? [])->not->toHaveKey('subtitle_check_tags');
});
