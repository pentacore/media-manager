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
        ->assertPresent('[data-gate="decision_gate"] [data-bar-chart-marker]')
        ->assertSeeIn('[data-gate="chat_routing"] [data-gate-summary]', 'No decisions in this window.');
});

test('the calibration chart shows a placeholder bar for unresolved bands and a real bar for resolved bands', function (): void {
    ClassificationOutcome::factory()->gate(ClassificationGate::DecisionGate)->create(['probability' => 0.15, 'verdict' => ClassificationVerdict::Skipped]);
    ClassificationOutcome::factory()->gate(ClassificationGate::DecisionGate)->resolved(true)->create(['probability' => 0.75, 'verdict' => ClassificationVerdict::AuditRun]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-usage.index', absolute: false))
        ->assertNoSmoke()
        ->assertVisible('[data-gate="decision_gate"] [data-gate-counts]')
        ->assertSeeIn('[data-gate="decision_gate"] [data-gate-counts] span:nth-child(1)', '0')
        ->assertSeeIn('[data-gate="decision_gate"] [data-gate-counts] span:nth-child(2)', '1')
        ->assertSeeIn('[data-gate="decision_gate"] [data-gate-counts] span:nth-child(8)', '1')
        ->assertAttribute('[data-gate="decision_gate"] rect[data-bar-placeholder]:nth-of-type(2)', 'data-bar-placeholder', 'true')
        ->assertAttribute('[data-gate="decision_gate"] rect[data-bar-placeholder]:nth-of-type(8)', 'data-bar-placeholder', 'false');
});

test('each gate count sits under its band label and bar, and a placeholder bar keeps a thin stroke', function (): void {
    ClassificationOutcome::factory()->gate(ClassificationGate::DecisionGate)->create(['probability' => 0.15, 'verdict' => ClassificationVerdict::Skipped]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-usage.index', absolute: false))
        ->assertNoSmoke()
        ->assertVisible('[data-gate="decision_gate"] [data-gate-counts]')
        ->assertSeeIn('[data-gate-caption]', 'the numbers below each chart count the events per band')
        ->assertScript(<<<'JS'
            (() => {
                const gate = document.querySelector('[data-gate="decision_gate"]');
                const centre = (el) => { const rect = el.getBoundingClientRect(); return rect.left + rect.width / 2; };
                const bars = [...gate.querySelectorAll('rect[data-bar-placeholder]')];
                const labels = [...gate.querySelectorAll('[data-bar-chart-labels] span')];
                const counts = [...gate.querySelectorAll('[data-gate-count]')];

                return bars.length > 0 && bars.length === labels.length && bars.length === counts.length
                    && bars.every((bar, index) => Math.abs(centre(bar) - centre(labels[index])) < 1.5 && Math.abs(centre(bar) - centre(counts[index])) < 1.5);
            })()
            JS, true)
        ->assertAttribute('[data-gate="decision_gate"] rect[data-bar-placeholder="true"]:nth-of-type(2)', 'vector-effect', 'non-scaling-stroke');
});
