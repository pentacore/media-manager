<?php

declare(strict_types=1);

use App\Enums\WhisparrVersion;
use App\Models\MediaReplacementAttempt;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\ServiceWarning;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * Every link styled as a button is one <a>: a button inside a link is
 * invalid HTML, reads as two controls to assistive tech, and lets a click
 * land on the inner element. Each test checks the page has no such nesting
 * and that the merged control still goes where it went.
 */

function linkButtonNestingCountScript(): string
{
    return "document.querySelectorAll('a button, a a, a input, a select, a textarea, a [role=\"button\"], a [role=\"checkbox\"], button a, button button').length";
}

/**
 * The outer HTML of every nesting the count script finds, for the failure message.
 */
function linkButtonNestingOffendersScript(): string
{
    return "[...document.querySelectorAll('a button, a a, a input, a select, a textarea, a [role=\"button\"], a [role=\"checkbox\"], button a, button button')].map((element) => element.closest('a, button')?.outerHTML.slice(0, 160))";
}

function linkButtonNestingAssertNone(mixed $webpage): void
{
    expect($webpage->script(linkButtonNestingCountScript()))->toBe(0, json_encode($webpage->script(linkButtonNestingOffendersScript())));
}

function linkButtonNestingFakeArr(): void
{
    Http::fake([
        'sonarr.local:8989/api/v3/series/7*' => Http::response([
            'id' => 7, 'title' => 'Example Series Title', 'titleSlug' => 'example-series-title', 'year' => 2026, 'status' => 'continuing',
            'monitored' => true, 'qualityProfileId' => 1, 'images' => [], 'seasons' => [],
            'statistics' => ['sizeOnDisk' => 0, 'episodeCount' => 0, 'episodeFileCount' => 0],
        ]),
        'sonarr.local:8989/api/v3/series' => Http::response([[
            'id' => 7, 'title' => 'Example Series Title', 'titleSlug' => 'example-series-title', 'year' => 2026, 'status' => 'continuing',
            'monitored' => true, 'qualityProfileId' => 1, 'images' => [], 'seasons' => [],
            'statistics' => ['sizeOnDisk' => 0, 'episodeCount' => 0, 'episodeFileCount' => 0],
        ]]),
        'sonarr.local:8989/api/v3/episode*' => Http::response([]),
        'sonarr.local:8989/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
        'sonarr.local:8989/api/v3/rootfolder' => Http::response([['id' => 1, 'path' => '/tv']]),
        'sonarr.local:8989/api/v3/tag' => Http::response([]),
        'sonarr.local:8989/api/v3/diskspace' => Http::response([]),
        'radarr.local:7878/api/v3/movie/10' => Http::response([
            'id' => 10, 'title' => 'Example Movie Title', 'titleSlug' => 'example-movie-title', 'year' => 2026,
            'monitored' => true, 'hasFile' => false, 'qualityProfileId' => 1, 'images' => [],
        ]),
        'radarr.local:7878/api/v3/movie' => Http::response([[
            'id' => 10, 'title' => 'Example Movie Title', 'titleSlug' => 'example-movie-title', 'year' => 2026,
            'monitored' => true, 'hasFile' => false, 'qualityProfileId' => 1, 'sizeOnDisk' => 0, 'images' => [],
        ]]),
        'radarr.local:7878/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'HD-1080p']]),
        'radarr.local:7878/api/v3/rootfolder' => Http::response([['id' => 1, 'path' => '/movies']]),
        'whisparr.local:6969/api/v3/movie/11*' => Http::response([
            'id' => 11, 'title' => 'Example Scene Title', 'year' => 2024, 'monitored' => true, 'hasFile' => false,
            'sizeOnDisk' => 0, 'qualityProfileId' => 1, 'images' => [],
        ]),
        'whisparr.local:6969/api/v3/movie' => Http::response([[
            'id' => 11, 'title' => 'Example Scene Title', 'year' => 2024, 'monitored' => true, 'hasFile' => false,
            'sizeOnDisk' => 0, 'qualityProfileId' => 1, 'images' => [],
        ]]),
        'whisparr.local:6969/api/v3/qualityprofile' => Http::response([['id' => 1, 'name' => 'Any']]),
    ]);
}

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
});

test('the header notification and assistant links are single anchors', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('dashboard', absolute: false))->assertNoSmoke();
    linkButtonNestingAssertNone($webpage);

    $webpage->click('[data-header-notifications]')
        ->assertPathIs(route('notifications.index', absolute: false))
        ->click('[data-header-ai-chat]')
        ->assertPathIs(route('ai.chat', absolute: false))
        ->assertNoSmoke();
});

/**
 * Playwright's role selector for a link whose accessible name is exactly
 * `$name`: the `s` flag makes the match whole and case-sensitive (Pest's
 * Selector::getByRoleSelector() omits it, which matches a substring).
 */
function linkButtonNestingLinkNamedSelector(string $name): string
{
    return sprintf('internal:role=link[name=%ss]', json_encode($name, JSON_THROW_ON_ERROR));
}

test('the header notifications link is named Notifications, with its unread count when there is one', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    visit(route('dashboard', absolute: false))
        ->assertNoSmoke()
        ->assertMissing('[data-unread-notifications]')
        ->assertPresent(linkButtonNestingLinkNamedSelector('Notifications'));

    // There is no DatabaseNotification factory; these rows mirror what
    // ServiceWarning::toArray() stores.
    foreach (range(1, 3) as $number) {
        $admin->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => ServiceWarning::class,
            'data' => ['service' => 'sabnzbd', 'title' => sprintf('Disk warning %d', $number), 'message' => 'Less than 1 GB free', 'level' => 'disk_full'],
        ]);
    }

    visit(route('dashboard', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-unread-notifications]', '3')
        ->assertPresent(linkButtonNestingLinkNamedSelector('Notifications 3'))
        ->assertMissing(linkButtonNestingLinkNamedSelector('3'));
});

test('the connection pages link to the form and back as single anchors', function (): void {
    $serviceConnection = ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    linkButtonNestingFakeArr();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.connections.index', absolute: false))->assertNoSmoke();
    linkButtonNestingAssertNone($webpage);

    $webpage->click('[data-connection-add]')
        ->assertPathIs(route('admin.connections.create', absolute: false));
    linkButtonNestingAssertNone($webpage);

    $webpage->click('[data-connection-cancel]')
        ->assertPathIs(route('admin.connections.index', absolute: false))
        ->click(sprintf('[data-connection-edit="%d"]', $serviceConnection->id))
        ->assertPathIs(route('admin.connections.edit', $serviceConnection, absolute: false));
    linkButtonNestingAssertNone($webpage);

    $webpage->click('[data-connection-cancel]')
        ->assertPathIs(route('admin.connections.index', absolute: false))
        ->assertNoSmoke();
});

test('the replacement attempts list opens an attempt through a single anchor', function (): void {
    $mediaReplacementAttempt = MediaReplacementAttempt::factory()->create();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.media-replacement.attempts.index', absolute: false))->assertNoSmoke();
    linkButtonNestingAssertNone($webpage);

    $webpage->click(sprintf('[data-attempt-view="%d"]', $mediaReplacementAttempt->id))
        ->assertPathIs(route('admin.media-replacement.attempts.show', $mediaReplacementAttempt, absolute: false))
        ->assertNoSmoke();
});

test('the webhook log opens an event and comes back through single anchors', function (): void {
    $webhookEvent = WebhookEvent::factory()->processed()->create();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('admin.webhook-log.index', absolute: false))->assertNoSmoke();
    linkButtonNestingAssertNone($webpage);

    $webpage->click(sprintf('[data-webhook-view="%d"]', $webhookEvent->id))
        ->assertPathIs(route('admin.webhook-log.show', $webhookEvent, absolute: false));
    linkButtonNestingAssertNone($webpage);

    $webpage->click('[data-webhook-back]')
        ->assertPathIs(route('admin.webhook-log.index', absolute: false))
        ->assertNoSmoke();
});

test('the Sonarr pages link between list, add form and series as single anchors', function (): void {
    ServiceConnection::factory()->sonarr()->create(['url' => 'http://sonarr.local:8989', 'api_key' => 'k']);
    linkButtonNestingFakeArr();
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.series.index', absolute: false))->assertNoSmoke();
    $webpage->assertPresent('[data-series-card="7"]');
    linkButtonNestingAssertNone($webpage);
    $webpage->assertCount('a[data-open-in-service]', 2)
        ->assertAttribute('a[data-open-in-service][href="http://sonarr.local:8989"]', 'target', '_blank');

    $webpage->click('[data-add-series]')
        ->assertPathIs(route('media.series.create', absolute: false));
    linkButtonNestingAssertNone($webpage);

    $webpage->click('[data-series-create-back]')
        ->assertPathIs(route('media.series.index', absolute: false));

    $webpage = visit(route('media.series.show', ['id' => 7], absolute: false))->assertNoSmoke();
    linkButtonNestingAssertNone($webpage);
    $webpage->assertAttribute('a[data-series-open-sonarr]', 'href', 'http://sonarr.local:8989/series/example-series-title')
        ->assertAttribute('a[data-series-open-sonarr]', 'target', '_blank')
        ->assertAttribute('a[data-series-open-sonarr]', 'rel', 'noopener noreferrer')
        ->click('[data-series-back]')
        ->assertPathIs(route('media.series.index', absolute: false))
        ->assertNoSmoke();
});

test('the Radarr pages link between list, add form and movie as single anchors', function (): void {
    ServiceConnection::factory()->radarr()->create(['url' => 'http://radarr.local:7878', 'api_key' => 'k']);
    linkButtonNestingFakeArr();
    $this->actingAs(User::factory()->member()->create());

    $webpage = visit(route('media.movies.index', absolute: false))->assertNoSmoke();
    $webpage->assertPresent('[data-movie-card="10"]');
    linkButtonNestingAssertNone($webpage);

    $webpage->click('[data-add-movie]')
        ->assertPathIs(route('media.movies.create', absolute: false));

    $webpage = visit(route('media.movies.show', ['id' => 10], absolute: false))->assertNoSmoke();
    linkButtonNestingAssertNone($webpage);
    $webpage->assertAttribute('a[data-movie-open-radarr]', 'href', 'http://radarr.local:7878/movie/example-movie-title')
        ->assertAttribute('a[data-movie-open-radarr]', 'target', '_blank')
        ->click('[data-movie-back]')
        ->assertPathIs(route('media.movies.index', absolute: false))
        ->assertNoSmoke();
});

test('the Whisparr title page links back and out as single anchors', function (): void {
    ServiceConnection::factory()->whisparr()->whisparrVersion(WhisparrVersion::V3)->create(['url' => 'http://whisparr.local:6969', 'api_key' => 'k']);
    linkButtonNestingFakeArr();
    $this->actingAs(User::factory()->admin()->create());

    $webpage = visit(route('media.whisparr.show', ['id' => 11], absolute: false))->assertNoSmoke();
    linkButtonNestingAssertNone($webpage);
    $webpage->assertAttribute('a[data-whisparr-open]', 'href', 'http://whisparr.local:6969')
        ->assertAttribute('a[data-whisparr-open]', 'target', '_blank')
        ->click('[data-whisparr-back]')
        ->assertPathIs(route('media.whisparr.index', absolute: false))
        ->assertNoSmoke();
});

test('the Authentik sign-in is a single anchor to the Authentik redirect', function (): void {
    config()->set('services.authentik.client_id', 'example-client');

    $webpage = visit(route('login', absolute: false))->assertNoSmoke();
    linkButtonNestingAssertNone($webpage);

    $webpage->assertSeeIn('a[data-login-authentik]', 'Sign in with Authentik')
        ->assertAttributeContains('a[data-login-authentik]', 'href', route('auth.authentik', absolute: false));
});
