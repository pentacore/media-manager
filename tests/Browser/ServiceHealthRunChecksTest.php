<?php

declare(strict_types=1);

use App\Models\User;

test('a viewer does not get the run-checks button', function (): void {
    $this->actingAs(User::factory()->create());

    visit(route('monitoring.service-health', absolute: false))
        ->assertNoSmoke()
        ->assertSee('Service health')
        ->assertMissing('[data-run-health-checks]');
});

test('a member can queue health checks from the service health page', function (): void {
    $this->actingAs(User::factory()->member()->create());

    visit(route('monitoring.service-health', absolute: false))
        ->assertNoSmoke()
        ->assertVisible('[data-run-health-checks]')
        ->click('[data-run-health-checks]')
        ->assertSee('Health checks queued for 0 service(s).');
});

test('a throttled health-check trigger shows a toast instead of the raw error dialog', function (): void {
    $this->actingAs(User::factory()->member()->create());

    $page = visit(route('monitoring.service-health', absolute: false))
        ->assertNoSmoke()
        ->assertVisible('[data-run-health-checks]');

    foreach (range(1, 6) as $attempt) {
        $page->click('[data-run-health-checks]');
    }

    $page->click('[data-run-health-checks]')
        ->assertSee('Health checks were just run — try again in a minute.')
        ->assertMissing('#inertia-error-dialog');
});
