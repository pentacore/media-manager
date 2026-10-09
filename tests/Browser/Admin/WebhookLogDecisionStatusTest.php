<?php

declare(strict_types=1);

use App\Models\AgentDecision;
use App\Models\User;
use App\Models\WebhookEvent;

/*
 * The decision pill on Admin → Webhook log renders every AgentDecisionStatus
 * readably, including the multi-word skipped_by_gate status written when the
 * classification gate skips a decision run.
 */

test('a gate-skipped decision renders as a readable pill on the webhook log list', function (): void {
    AgentDecision::factory()->skippedByGate()->for(WebhookEvent::factory()->processed())->create();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.webhook-log.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-decision-status="skipped_by_gate"]', 'skipped by gate');
});

test('a gate-skipped decision shows its pill and gate summary on the webhook detail page', function (): void {
    $webhookEvent = WebhookEvent::factory()->processed()->create();
    AgentDecision::factory()->skippedByGate()->for($webhookEvent)->create();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.webhook-log.show', $webhookEvent, absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-decision-status="skipped_by_gate"]', 'skipped by gate')
        ->assertSeeIn('[data-decision-summary]', 'Skipped by the classification gate');
});

test('a classifier-resolved decision renders as a readable pill on the webhook log', function (): void {
    $webhookEvent = WebhookEvent::factory()->processed()->create();
    AgentDecision::factory()->resolvedByClassifier()->for($webhookEvent)->create();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.webhook-log.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-decision-status="resolved_by_classifier"]', 'resolved by classifier');

    visit(route('admin.webhook-log.show', $webhookEvent, absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-decision-summary]', 'Resolved by the classifier');
});
