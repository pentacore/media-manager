<?php

declare(strict_types=1);

use App\Enums\SecondOpinionMode;
use App\Models\ActionTypeConfig;
use App\Models\User;

test('an admin picks a second opinion on an approval rule', function (): void {
    $actionTypeConfig = ActionTypeConfig::factory()->create(['type' => 'monitor_series', 'label' => 'Monitor series']);
    ActionTypeConfig::factory()->create(['type' => 'grab_release', 'label' => 'Grab release']);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('actions.rules.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-rule-second-opinion="grab_release"]', '—')
        ->click('[data-rule-second-opinion="monitor_series"] [data-second-opinion-option="hold"]')
        ->assertAttribute('[data-rule-second-opinion="monitor_series"] [data-second-opinion-option="hold"]', 'aria-pressed', 'true');

    expect($actionTypeConfig->fresh()->second_opinion)->toBe(SecondOpinionMode::Hold);
});
