<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function (): void {
    config()->set('inertia.testing.ensure_pages_exist', false);
});

it('requires authentication', function (): void {
    $this->get(route('admin.statistics.index'))->assertRedirect(route('login'));
});

it('renders for admins with the operational props', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.statistics.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Statistics/Index')
            ->has('webhookSeries')
            ->has('webhooksByService')
            ->has('actionsByStatus')
            ->has('actionsByOrigin')
            ->has('agentDecisions')
            ->has('diskSeries')
            ->has('queueSeries')
            ->has('sessionSeries')
            ->has('uptime')
            ->has('aiCostSeries')
            ->has('headline.approvalRate')
            ->has('headline.agentNoActionRate')
            ->has('windows')
            ->where('window', '30d'));
});

it('honours the window query parameter', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.statistics.index', ['window' => '7d']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Statistics/Index')
            ->where('window', '7d'));
});

it('is forbidden for regular users', function (): void {
    $this->actingAs(User::factory()->member()->create())
        ->get(route('admin.statistics.index'))
        ->assertForbidden();
});
