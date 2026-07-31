<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

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

    // arrTags is deferred, like every other upstream read on this page: an
    // active-but-unreachable arr would otherwise block the initial render for
    // the client's connect timeout times its retries.
    $this->actingAs($this->admin)
        ->get(route('admin.connections.edit', $connection))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Admin/Connections/Edit')
            ->where('subtitleCheckTags', ['sub-check'])
            ->loadDeferredProps(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('arrTags', [
                    ['id' => 1, 'label' => 'sub-check'],
                    ['id' => 2, 'label' => 'anime'],
                ])));
});

test('the arr tags prop is deferred rather than resolved during the initial render', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/tag' => Http::response([['id' => 1, 'label' => 'sub-check']]),
        'sonarr.local:8989/api/v3/rootfolder' => Http::response([]),
    ]);

    $this->actingAs($this->admin)
        ->get(route('admin.connections.edit', $connection))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->missing('arrTags'));

    // The upstream call belongs to the follow-up request, not the page render.
    Http::assertNothingSent();
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
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->loadDeferredProps(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('arrTags', null)));

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

// The two directions below are the whole contract of the is_array() guard, and
// they must stay distinguishable: an EMPTY array clears the selection, an ABSENT
// key preserves it. Without the first, unticking every box in the picker is
// unsavable and the only way to disable the feature is editing the database.
test('submitting an empty selection clears the stored tags', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
        'settings' => ['subtitle_check_tags' => ['sub-check', 'anime']],
    ]);

    $this->actingAs($this->admin)
        ->put(route('admin.connections.update', $connection), [
            'type' => 'sonarr',
            'name' => $connection->name,
            'url' => 'http://sonarr.local:8989',
            // What the browser actually sends when every checkbox is unticked:
            // the group contributes nothing, so the form carries only the empty
            // hidden input that keeps the field present. It reaches the request
            // as [null], because ConvertEmptyStringsToNull runs first — which is
            // why the per-entry rule has to be `nullable` and not `required`.
            'subtitle_check_tags' => [''],
        ])
        ->assertRedirect();

    expect($connection->fresh()->settings['subtitle_check_tags'])->toBe([]);
});

test('submitting a genuinely empty array clears the stored tags', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
        'settings' => ['subtitle_check_tags' => ['sub-check']],
    ]);

    $this->actingAs($this->admin)
        ->put(route('admin.connections.update', $connection), [
            'type' => 'sonarr',
            'name' => $connection->name,
            'url' => 'http://sonarr.local:8989',
            'subtitle_check_tags' => [],
        ])
        ->assertRedirect();

    expect($connection->fresh()->settings['subtitle_check_tags'])->toBe([]);
});

test('clearing the tags leaves sibling connection settings intact', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989', 'api_key' => 'test', 'is_active' => true,
        'settings' => [
            'subtitle_check_tags' => ['sub-check'],
            'sonarr_root_folders' => [['root_folder_id' => 1, 'path' => '/tv', 'scope' => 'tv']],
        ],
    ]);

    $this->actingAs($this->admin)
        ->put(route('admin.connections.update', $connection), [
            'type' => 'sonarr',
            'name' => $connection->name,
            'url' => 'http://sonarr.local:8989',
            'subtitle_check_tags' => [''],
        ])
        ->assertRedirect();

    $settings = $connection->fresh()->settings;

    expect($settings['subtitle_check_tags'])->toBe([])
        ->and($settings['sonarr_root_folders'])->toBe([
            ['root_folder_id' => 1, 'path' => '/tv', 'scope' => 'tv'],
        ]);
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
