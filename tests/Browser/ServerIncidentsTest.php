<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\ServerIncident;
use App\Models\User;

test('an open incident is listed and links to its server page anchor', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $server = Server::factory()->enrolled()->create(['name' => 'babel']);
    $open = ServerIncident::factory()->for($server)->open()->critical()->create(['title' => 'Disk almost full']);
    $resolved = ServerIncident::factory()->for($server)->resolved()->create(['title' => 'Old trouble']);

    $webpage = visit(route('admin.servers.incidents.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn(sprintf('[data-incident-index-row="%d"] [data-incident-title]', $open->id), 'Disk almost full')
        ->assertSeeIn(sprintf('[data-incident-index-row="%d"] [data-incident-status]', $open->id), 'Open')
        ->assertSeeIn(sprintf('[data-incident-index-row="%d"] [data-incident-status]', $resolved->id), 'Resolved');

    $webpage->script(appHydratedScript());

    $webpage->click(sprintf('[data-incident-index-row="%d"] [data-incident-server]', $open->id))
        ->assertPathIs(route('admin.servers.show', $server, absolute: false))
        ->assertScript('window.location.hash', '#incident-'.$open->id);
});

test('filtering by server shows only its incidents', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $babel = Server::factory()->enrolled()->create(['name' => 'babel']);
    $zeta = Server::factory()->enrolled()->create(['name' => 'zeta']);
    $babelIncident = ServerIncident::factory()->for($babel)->open()->create();
    $zetaIncident = ServerIncident::factory()->for($zeta)->open()->create();

    $webpage = visit(route('admin.servers.incidents.index', absolute: false))
        ->assertNoSmoke()
        ->assertVisible(sprintf('[data-incident-index-row="%d"]', $babelIncident->id))
        ->assertVisible(sprintf('[data-incident-index-row="%d"]', $zetaIncident->id));

    $webpage->script(appHydratedScript());

    $webpage->click('[data-incident-filter]')
        ->click('[role="option"][aria-label="zeta"]')
        ->assertVisible(sprintf('[data-incident-index-row="%d"]', $zetaIncident->id))
        ->assertMissing(sprintf('[data-incident-index-row="%d"]', $babelIncident->id));
});

test('a newly opened incident appears without a reload', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    $server = Server::factory()->enrolled()->create(['name' => 'babel']);

    $webpage = visit(route('admin.servers.incidents.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-incident-empty]', 'No incidents. Every server is healthy.');

    $webpage->script(appHydratedScript());

    $serverIncident = ServerIncident::factory()->for($server)->open()->critical()->create(['title' => 'Memory exhausted']);
    $webpage->script(echoEmitScript('admin.servers', 'ServerIncidentChanged', [
        'server_id' => $server->id, 'incident_id' => $serverIncident->id, 'key' => $serverIncident->key,
        'status' => 'open', 'severity' => 'critical', 'title' => 'Memory exhausted', 'occurred_at' => now()->toIso8601String(),
    ]));

    $webpage->assertSeeIn(sprintf('[data-incident-index-row="%d"] [data-incident-title]', $serverIncident->id), 'Memory exhausted')
        ->assertMissing('[data-incident-empty]');
});
