<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function (): void {
    config()->set('mediamanager.ai.enabled', true);
    $this->actingAs(User::factory()->admin()->create());
});

test('a toggle is a switch whose checked state flips on click', function (): void {
    $webpage = visit(route('admin.decision-agent.index', absolute: false))
        ->assertNoSmoke();
    $webpage->script(appHydratedScript());
    $webpage->assertAttribute('[data-decision-agent-enabled]', 'role', 'switch');

    $wasChecked = $webpage->script('document.querySelector("[data-decision-agent-enabled]").getAttribute("aria-checked")');

    $webpage->click('[data-decision-agent-enabled]')
        ->assertAttribute('[data-decision-agent-enabled]', 'aria-checked', $wasChecked === 'true' ? 'false' : 'true')
        ->click('[data-decision-agent-enabled]')
        ->assertAttribute('[data-decision-agent-enabled]', 'aria-checked', $wasChecked);
});

test('a linked toggle is named by its row label and hides its on and off text', function (): void {
    visit(route('admin.decision-agent.index', absolute: false))
        ->assertNoSmoke()
        ->assertAttribute('[data-decision-agent-enabled]', 'aria-labelledby', 'decision-agent-enabled-label')
        ->assertScript('document.getElementById("decision-agent-enabled-label").textContent.trim()', 'Enabled')
        ->assertScript('[...document.querySelector("[data-decision-agent-enabled]").children].at(-1).getAttribute("aria-hidden")', 'true');
});

test('clicking the label of a linked toggle toggles it exactly once', function (): void {
    $webpage = visit(route('admin.decision-agent.index', absolute: false))
        ->assertNoSmoke();
    $webpage->script(appHydratedScript());

    $wasChecked = $webpage->script('document.querySelector("[data-decision-agent-enabled]").getAttribute("aria-checked")');

    $webpage->click('[data-decision-agent-enabled-row] label');
    $webpage->script('new Promise((resolve) => setTimeout(resolve, 100))');
    $webpage->assertAttribute('[data-decision-agent-enabled]', 'aria-checked', $wasChecked === 'true' ? 'false' : 'true');
});
