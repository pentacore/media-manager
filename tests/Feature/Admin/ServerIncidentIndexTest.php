<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\ServerIncident;
use App\Models\User;

test('open incidents list before resolved ones', function (): void {
    $server = Server::factory()->create(['name' => 'babel']);
    $resolved = ServerIncident::factory()->for($server)->resolved()->create(['opened_at' => now()->subMinute()]);
    $open = ServerIncident::factory()->for($server)->open()->critical()->create(['opened_at' => now()->subDay()]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.servers.incidents.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Servers/Incidents')
            ->has('incidents.data', 2)
            ->where('incidents.data.0.id', $open->id)
            ->where('incidents.data.0.server_id', $server->id)
            ->where('incidents.data.0.server_name', 'babel')
            ->where('incidents.data.0.severity', 'critical')
            ->where('incidents.data.0.severity_label', 'Critical')
            ->where('incidents.data.0.status', 'open')
            ->where('incidents.data.1.id', $resolved->id)
            ->where('filters.server', null)
            ->has('servers', 1));
});

test('the server filter narrows the list', function (): void {
    $babel = Server::factory()->create(['name' => 'babel']);
    $zeta = Server::factory()->create(['name' => 'zeta']);
    ServerIncident::factory()->for($babel)->open()->create();
    $zetaIncident = ServerIncident::factory()->for($zeta)->open()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.servers.incidents.index', ['server' => $zeta->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('incidents.data', 1)
            ->where('incidents.data.0.id', $zetaIncident->id)
            ->where('filters.server', $zeta->id)
            ->has('servers', 2));
});

test('the server filter must be an existing numeric id', function (string $value): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.servers.incidents.index', ['server' => $value]))
        ->assertSessionHasErrors('server');
})->with(['text' => 'abc', 'missing' => '99999']);

test('the list paginates at 25', function (): void {
    ServerIncident::factory()->for(Server::factory())->count(30)->open()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.servers.incidents.index'))
        ->assertInertia(fn ($page) => $page
            ->has('incidents.data', 25)
            ->where('incidents.meta.last_page', 2)
            ->where('incidents.meta.total', 30)
            ->where('incidents.meta.per_page', 25));
});

test('only admins can open the incidents page', function (): void {
    $this->actingAs(User::factory()->member()->create())
        ->get(route('admin.servers.incidents.index'))
        ->assertForbidden();
});
