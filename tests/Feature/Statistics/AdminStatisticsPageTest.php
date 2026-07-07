<?php

declare(strict_types=1);

use App\Models\StatRollup;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    config()->set('inertia.testing.ensure_pages_exist', false);
});

/**
 * Seed a day-period `actions.by_status` rollup for the given status.
 */
function seedActionStatus(string $status, int $count): void
{
    StatRollup::factory()->create([
        'metric' => 'actions.by_status',
        'period' => 'day',
        'bucket' => CarbonImmutable::now('UTC')->startOfDay(),
        'dimensions' => ['status' => $status],
        'count' => $count,
        'sum' => null,
    ]);
}

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
            ->has('headline.resolvedRate')
            ->has('headline.agentNoActionRate')
            ->has('windows')
            ->where('window', '30d'));
});

it('computes approval and resolved rates from action status rollups', function (): void {
    // approved-side: approved 6 + executing 2 + completed 4 = 12
    seedActionStatus('approved', 6);
    seedActionStatus('executing', 2);
    seedActionStatus('completed', 4);
    seedActionStatus('rejected', 4);
    seedActionStatus('failed', 2);
    seedActionStatus('pending', 6);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.statistics.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Statistics/Index')
            // approved (12) / decided (approved 12 + rejected 4 = 16) = 75%
            ->where('headline.approvalRate', 75)
            // terminal (completed 4 + failed 2 + rejected 4 = 10) / total 24 = 42%
            ->where('headline.resolvedRate', 42));
});

it('reports zero rates when there are no action rollups', function (): void {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.statistics.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('headline.approvalRate', 0)
            ->where('headline.resolvedRate', 0));
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
