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
    $connection = makeSonarrConnectionWithStoredTag();

    fakeSonarrSubtitleCheckTagEndpoints();

    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.connections.edit', $connection, absolute: false))
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
    $connection = makeSonarrConnectionWithStoredTag();

    fakeSonarrSubtitleCheckTagEndpoints();

    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.connections.edit', $connection, absolute: false))
        ->assertSee('sub-check')
        // Untick the only selected tag, so the checkbox group contributes
        // nothing to the payload. Only the empty hidden input keeps the field
        // present; without it the request omits it and the backend preserves
        // the old value, making the feature impossible to switch off.
        ->uncheck('input[name="subtitle_check_tags[]"][value="sub-check"]')
        ->click('Update Connection')
        ->assertPathIs('/admin/connections')
        ->assertNoJavascriptErrors();

    expect($connection->fresh()->settings['subtitle_check_tags'])->toBe([]);
});

test('an unreachable instance submits no tag field, so a save preserves the stored tags', function (): void {
    $connection = makeSonarrConnectionWithStoredTag();

    Http::fake([
        'sonarr.local:8989/api/v3/tag' => Http::response(status: 500),
        'sonarr.local:8989/api/v3/rootfolder' => Http::response([]),
        'sonarr.local:8989/api/v3/diskspace' => Http::response([]),
    ]);

    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.connections.edit', $connection, absolute: false))
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

    expect($connection->fresh()->settings['subtitle_check_tags'])->toBe(['sub-check']);
});
