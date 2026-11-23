<?php

declare(strict_types=1);

use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('an admin can set the subtitle repair search slots on a sonarr connection', function (): void {
    $serviceConnection = ServiceConnection::factory()->sonarr()->create([
        'url' => 'http://sonarr.local:8989',
        'api_key' => 'test',
        'is_active' => true,
    ]);

    // Scoped patterns, never a '*' catch-all: that would also answer Inertia's
    // SSR POST with an empty body and render the page blank.
    Http::fake([
        'sonarr.local:8989/api/v3/tag' => Http::response([]),
        'sonarr.local:8989/api/v3/rootfolder' => Http::response([]),
        'sonarr.local:8989/api/v3/diskspace' => Http::response([]),
    ]);

    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.connections.edit', $serviceConnection, absolute: false))
        ->assertSee('Subtitle repair searches at once')
        ->fill('#subtitle_audit_search_slots', '4')
        ->click('Update Connection')
        ->assertPathIs('/admin/connections')
        ->assertNoJavascriptErrors();

    expect($serviceConnection->fresh()->subtitleAuditSearchSlots())->toBe(4);
});
