<?php

declare(strict_types=1);

use App\Enums\ClassificationGate;
use App\Enums\ClassificationVerdict;
use App\Models\ClassificationOutcome;
use App\Models\User;

test('the AI usage page shows a calibration card per classification gate', function (): void {
    ClassificationOutcome::factory()->gate(ClassificationGate::DecisionGate)->resolved(true)->create(['probability' => 0.1, 'verdict' => ClassificationVerdict::AuditRun]);
    ClassificationOutcome::factory()->gate(ClassificationGate::DecisionGate)->create(['probability' => 0.1, 'verdict' => ClassificationVerdict::Skipped]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-usage.index', absolute: false))
        ->assertNoSmoke()
        ->assertVisible('[data-classification-gates]')
        ->assertSeeIn('[data-gate="decision_gate"]', 'Webhook decision gate')
        ->assertSeeIn('[data-gate="decision_gate"] [data-gate-summary]', '1 of 1 resolved audit run(s) needed action')
        ->assertVisible('[data-gate="decision_gate"] [data-bar-chart-marker]')
        ->assertSeeIn('[data-gate="chat_routing"] [data-gate-summary]', 'No decisions in this window.');
});

test('the calibration chart shows a placeholder bar for unresolved bands and a real bar for resolved bands', function (): void {
    ClassificationOutcome::factory()->gate(ClassificationGate::DecisionGate)->create(['probability' => 0.15, 'verdict' => ClassificationVerdict::Skipped]);
    ClassificationOutcome::factory()->gate(ClassificationGate::DecisionGate)->resolved(true)->create(['probability' => 0.75, 'verdict' => ClassificationVerdict::AuditRun]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-usage.index', absolute: false))
        ->assertNoSmoke()
        ->assertVisible('[data-gate="decision_gate"] [data-gate-counts]')
        ->assertSeeIn('[data-gate="decision_gate"] [data-gate-counts]', '–')
        ->assertVisible('[data-gate="decision_gate"] rect[data-bar-placeholder="true"]')
        ->assertVisible('[data-gate="decision_gate"] rect[data-bar-placeholder="false"]');
});
