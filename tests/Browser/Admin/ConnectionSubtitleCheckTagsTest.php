<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('an admin can see and toggle subtitle-check tags on a sonarr connection', function (): void {
    $connection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'test',
        'is_active' => true,
        'settings' => ['subtitle_check_tags' => ['sub-check']],
    ]);

    // Scoped fakes only. A '*' catch-all also answers Inertia's SSR POST with
    // an empty body, which renders the page blank.
    Http::fake([
        'sonarr.local:8989/api/v3/tag' => Http::response([
            ['id' => 1, 'label' => 'sub-check'],
            ['id' => 2, 'label' => 'anime'],
        ]),
        'sonarr.local:8989/api/v3/rootfolder' => Http::response([]),
        'sonarr.local:8989/api/v3/diskspace' => Http::response([]),
    ]);

    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.connections.edit', $connection, absolute: false))
        ->assertSee('Automatic subtitle check')
        ->assertSee('sub-check')
        ->assertSee('anime')
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
