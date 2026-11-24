<?php

declare(strict_types=1);

use App\Models\User;
use App\Settings\DecisionAgentSettings;

test('an admin sets the decision run time limit', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.decision-agent.index', absolute: false))
        ->assertNoSmoke()
        ->assertValue('[data-decision-agent-run-time-limit]', '210')
        ->assertSee('Seconds one decision run may take, 30–210.')
        ->fill('[data-decision-agent-run-time-limit]', '120')
        ->press('Save settings')
        ->assertSee('Decision agent settings updated.');

    expect(resolve(DecisionAgentSettings::class)->runTimeLimit())->toBe(120);
});
