<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Library\InterventionCounter;
use App\Services\Library\WantedCounter;
use App\Support\AppVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('inertia.testing.ensure_pages_exist', false);
});

test('shared auth.user exposes only safe fields', function (): void {
    $user = User::factory()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'role' => UserRole::Admin,
        'password' => bcrypt('super-secret'),
    ]);
    $user->forceFill([
        'remember_token' => 'a-secret-remember-token',
    ])->save();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.id', $user->id)
            ->where('auth.user.name', 'Test User')
            ->where('auth.user.email', 'test@example.com')
            ->where('auth.user.role', UserRole::Admin->value)
            ->has('auth.user.email_verified_at')
            ->has('auth.user.avatar_url')
            // Sensitive fields must not be present in the shared payload.
            ->missing('auth.user.password')
            ->missing('auth.user.remember_token')
            ->missing('auth.user.two_factor_secret')
            ->missing('auth.user.two_factor_recovery_codes')
            ->missing('auth.user.two_factor_confirmed_at')
            ->missing('auth.user.sso_provider')
            ->missing('auth.user.sso_id')
        );
});

test('shared auth.user is null for guests', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.user', null));
});

test('libraryIntervention warms a cold cache on first request', function (): void {
    Cache::forget(InterventionCounter::CACHE_KEY);

    ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'k',
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/queue*' => Http::response([
            'records' => [
                ['trackedDownloadStatus' => 'warning', 'trackedDownloadState' => 'importBlocked'],
                ['trackedDownloadStatus' => 'ok', 'trackedDownloadState' => 'downloading'],
            ],
        ]),
    ]);

    $user = User::factory()->member()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('nav.libraryIntervention', 1));

    expect(Cache::get(InterventionCounter::CACHE_KEY))->toBe(1);
});

test('libraryIntervention reads cache when already populated', function (): void {
    Cache::put(InterventionCounter::CACHE_KEY, 7, 60);

    $user = User::factory()->member()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('nav.libraryIntervention', 7));
});

test('shares version data with authenticated users', function (): void {
    config()->set('app.version', '1.7.2');
    Cache::put(AppVersion::CACHE_KEY, '1.8.0', 60);

    $user = User::factory()->member()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('version.current', '1.7.2')
            ->where('version.latest', '1.8.0')
            ->where('version.updateAvailable', true)
        );
});

test('version update hint is off for dev builds', function (): void {
    config()->set('app.version', 'dev');
    Cache::put(AppVersion::CACHE_KEY, '1.8.0', 60);

    $user = User::factory()->member()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('version.current', 'dev')
            ->where('version.updateAvailable', false)
        );
});

test('version is not shared with guests', function (): void {
    config()->set('app.version', '1.7.2');

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('version', null));
});

test('shared auth.can carries the viewer abilities', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.can.view-library', true)
            ->where('auth.can.request-media', true)
            ->where('auth.can.manage-library', false)
            ->where('auth.can.manage-requests', false)
            ->where('auth.can.admin', false));
});

test('shared auth.can is all false for guests', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.can.view-library', false)
            ->where('auth.can.admin', false));
});

test('integrations.seerr reports whether an active Seerr connection exists', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('integrations.seerr', false));

    ServiceConnection::factory()->seerr()->create(['url' => 'http://seerr.local:5055']);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('integrations.seerr', true));
});

test('nav.replacementAttention counts unacknowledged needs_attention attempts for admins only', function (): void {
    MediaReplacementAttempt::factory()->needsAttention()->create();
    MediaReplacementAttempt::factory()->needsAttention()->acknowledged()->create();
    MediaReplacementAttempt::factory()->verified()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('nav.replacementAttention', 1));

    $this->actingAs(User::factory()->member()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('nav.replacementAttention', 0));
});

test('nav.wantedMissing is the cached missing count for members and zero for viewers', function (): void {
    Cache::put(WantedCounter::CACHE_KEY, 7, 600);

    $this->actingAs(User::factory()->member()->create())->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('nav.wantedMissing', 7));

    $this->actingAs(User::factory()->create())->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('nav.wantedMissing', 0));
});
