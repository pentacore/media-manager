<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Scoped Http::fake patterns everywhere below, never a '*' catch-all: the
 * catch-all also answers Inertia's SSR POST with an empty body, which renders
 * the page blank.
 */
function fakeSonarrSubtitleCheckTagEndpoints(): void
{
    Http::fake([
        'sonarr.local:8989/api/v3/tag' => Http::response([
            ['id' => 1, 'label' => 'sub-check'],
            ['id' => 2, 'label' => 'anime'],
        ]),
        'sonarr.local:8989/api/v3/rootfolder' => Http::response([]),
        'sonarr.local:8989/api/v3/diskspace' => Http::response([]),
    ]);
}

function makeSonarrConnectionWithStoredTag(): ServiceConnection
{
    return ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'test',
        'is_active' => true,
        'settings' => ['subtitle_check_tags' => ['sub-check']],
    ]);
}

test('an admin can see and toggle subtitle-check tags on a sonarr connection', function (): void {
    $serviceConnection = makeSonarrConnectionWithStoredTag();

    fakeSonarrSubtitleCheckTagEndpoints();

    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.connections.edit', $serviceConnection, absolute: false))
        ->assertSee('Automatic subtitle check')
        // arrTags is deferred now, so the labels only appear once the follow-up
        // request resolves — which also proves the deferred prop reaches the
        // picker rather than leaving it stuck on "Loading tags…".
        ->assertSee('sub-check')
        ->assertSee('anime')
        ->assertDontSee('Loading tags…')
        // The stored selection must arrive pre-checked and the unstored tag
        // must not. Checked state is invisible to the text assertions above,
        // so it needs the DOM.
        ->assertScript(
            "document.querySelector('input[name=\"subtitle_check_tags[]\"][value=\"sub-check\"]').checked",
            true,
        )
        ->assertScript(
            "document.querySelector('input[name=\"subtitle_check_tags[]\"][value=\"anime\"]').checked",
            false,
        )
        ->assertNoJavascriptErrors();
});

test('an admin can untick every tag and have the cleared selection persist', function (): void {
    $serviceConnection = makeSonarrConnectionWithStoredTag();

    fakeSonarrSubtitleCheckTagEndpoints();

    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.connections.edit', $serviceConnection, absolute: false))
        ->assertSee('sub-check')
        // Untick the only selected tag, so the checkbox group contributes
        // nothing to the payload. Only the empty hidden input keeps the field
        // present; without it the request omits it and the backend preserves
        // the old value, making the feature impossible to switch off.
        ->uncheck('input[name="subtitle_check_tags[]"][value="sub-check"]')
        ->click('Update Connection')
        ->assertPathIs('/admin/connections')
        ->assertNoJavascriptErrors();

    expect($serviceConnection->fresh()->settings['subtitle_check_tags'])->toBe([]);
});

/*
 * The loading branch is what renders before the deferred prop arrives, so it is
 * the picker's actual first paint. It cannot be asserted through visit(): Pest
 * waits for network idle, so the deferred request has always landed by the time
 * the first assertion runs — delaying the upstream response only delays visit()
 * with it. The server-rendered HTML is that same first paint without the race,
 * so this asserts on it directly. Like SsrHydrationTest in this suite, it needs
 * `artisan inertia:start-ssr`.
 */
test('the server-rendered first paint says tags are loading, not that they failed', function (): void {
    $serviceConnection = makeSonarrConnectionWithStoredTag();

    fakeSonarrSubtitleCheckTagEndpoints();

    $html = (string) $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.connections.edit', $serviceConnection))
        ->getContent();

    expect($html)->toContain('Automatic subtitle check')
        ->and($html)->toContain('Loading tags')
        // Mistaking "not yet arrived" for "failed" is the specific regression
        // here: it would tell every admin their URL and API key are wrong on
        // every page load.
        ->and($html)->not->toContain('Tags could not be loaded')
        ->and($html)->not->toContain('No tags are defined');
});

test('a padded upstream label still renders its stored tag as checked', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'test',
        'is_active' => true,
        // Stored trimmed, as SubtitleCheckTagSettings always stores it.
        'settings' => ['subtitle_check_tags' => ['anime']],
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/tag' => Http::response([
            ['id' => 1, 'label' => '  anime  '],
        ]),
        'sonarr.local:8989/api/v3/rootfolder' => Http::response([]),
        'sonarr.local:8989/api/v3/diskspace' => Http::response([]),
    ]);

    $this->actingAs(User::factory()->admin()->create());

    // Untrimmed, the checkbox value would be "  anime  ", so this selector finds
    // nothing and the configured tag renders unchecked — then silently clears on
    // the next save of any field on this page.
    visit(route('admin.connections.edit', $connection, absolute: false))
        ->assertScript(
            "document.querySelector('input[name=\"subtitle_check_tags[]\"][value=\"anime\"]').checked",
            true,
        )
        ->assertNoJavascriptErrors();
});

test('an instance with no tags says so instead of reporting a failure', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'test',
        'is_active' => true,
    ]);

    Http::fake([
        'sonarr.local:8989/api/v3/tag' => Http::response([]),
        'sonarr.local:8989/api/v3/rootfolder' => Http::response([]),
        'sonarr.local:8989/api/v3/diskspace' => Http::response([]),
    ]);

    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.connections.edit', $connection, absolute: false))
        ->assertSee('No tags are defined on this instance yet.')
        // An empty list is a successful answer, not a failure: it must not fall
        // through to the unavailable copy.
        ->assertScript(
            "document.querySelectorAll('[data-testid=\"subtitle-check-tags-unavailable\"]').length",
            0,
        )
        ->assertScript(
            "document.querySelectorAll('input[name=\"subtitle_check_tags[]\"]').length",
            0,
        )
        ->assertNoJavascriptErrors();
});

test('an unreachable instance submits no tag field, so a save preserves the stored tags', function (): void {
    $serviceConnection = makeSonarrConnectionWithStoredTag();

    Http::fake([
        'sonarr.local:8989/api/v3/tag' => Http::response(status: 500),
        'sonarr.local:8989/api/v3/rootfolder' => Http::response([]),
        'sonarr.local:8989/api/v3/diskspace' => Http::response([]),
    ]);

    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.connections.edit', $serviceConnection, absolute: false))
        ->assertSee('Tags could not be loaded')
        // No inputs at all, not even the presence-keeping hidden one: claiming
        // an empty selection here would wipe the stored tags on any unrelated
        // edit made while the instance happens to be down.
        ->assertScript(
            "document.querySelectorAll('input[name=\"subtitle_check_tags[]\"]').length",
            0,
        )
        ->click('Update Connection')
        ->assertPathIs('/admin/connections')
        ->assertNoJavascriptErrors();

    expect($serviceConnection->fresh()->settings['subtitle_check_tags'])->toBe(['sub-check']);
});
