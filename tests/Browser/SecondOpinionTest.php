<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Enums\SecondOpinionEngine;
use App\Enums\SecondOpinionMode;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\User;
use App\Settings\DecisionAgentSettings;

test('an admin picks a second opinion on an approval rule', function (): void {
    $actionTypeConfig = ActionTypeConfig::factory()->create(['type' => 'monitor_series', 'label' => 'Monitor series']);
    ActionTypeConfig::factory()->create(['type' => 'grab_release', 'label' => 'Grab release']);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('actions.rules.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-rule-second-opinion="grab_release"]', '—')
        ->click('[data-rule-second-opinion="monitor_series"] [data-second-opinion-option="hold"]')
        ->assertAttribute('[data-rule-second-opinion="monitor_series"] [data-second-opinion-option="hold"]', 'aria-pressed', 'true')
        ->assertSee('Rule updated.');

    expect($actionTypeConfig->fresh()->second_opinion)->toBe(SecondOpinionMode::Hold);
});

test('jev engines are disabled until a classifier is configured', function (): void {
    config(['mediamanager.ai.classification.provider' => 'openrouter', 'ai.providers.openrouter.key' => '']);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.decision-agent.index', absolute: false))
        ->assertNoSmoke()
        ->assertVisible('[data-second-opinion-classifier-hint]')
        ->click('[data-second-opinion-engine]')
        ->assertAttribute('[data-second-opinion-engine-option="jev"]', 'data-disabled', '');
});

test('an admin switches the second opinion to jev decides with a threshold', function (): void {
    config(['mediamanager.ai.classification.provider' => 'openrouter', 'ai.providers.openrouter.key' => 'sk-test']);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.decision-agent.index', absolute: false))
        ->assertNoSmoke()
        ->assertMissing('[data-second-opinion-threshold]')
        ->click('[data-second-opinion-engine]')
        ->click('[data-second-opinion-engine-option="jev"]')
        ->fill('[data-second-opinion-threshold]', '0.65')
        ->press('Save settings')
        ->assertSee('Decision agent settings updated.');

    expect(resolve(DecisionAgentSettings::class)->secondOpinionEngine())->toBe(SecondOpinionEngine::Jev)
        ->and(resolve(DecisionAgentSettings::class)->secondOpinionJevThreshold())->toBe(0.65);
});

test('the action queue card shows the second opinion', function (bool $agrees, bool $failed, string $state, string $text): void {
    $actionRequest = ActionRequest::factory()->secondOpinion(agrees: $agrees, failed: $failed, reason: 'Checked the event.')->create([
        'origin' => 'agent', 'status' => ActionRequestStatus::Pending, 'requires_approval' => true,
    ]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->click(sprintf('[data-action-row="%d"]', $actionRequest->id))
        ->assertAttribute('[data-action-second-opinion]', 'data-second-opinion-state', $state)
        ->assertSeeIn('[data-action-second-opinion]', $text)
        ->assertSeeIn('[data-action-second-opinion]', 'Checked the event.')
        ->assertMissing('[data-second-opinion-override-event]');
})->with([
    'agrees' => [true, false, 'agrees', 'Second opinion: agrees'],
    'disagrees' => [false, false, 'disagrees', 'Second opinion: disagrees'],
    'failed' => [false, true, 'failed', 'Second opinion: check failed'],
]);

test('a request the second opinion rejected says so', function (): void {
    $actionRequest = ActionRequest::factory()->secondOpinion(agrees: false, reason: 'Wrong series.', mode: 'reject')->create([
        'origin' => 'agent',
        'status' => ActionRequestStatus::Rejected,
        'result' => ['success' => false, 'reason' => 'second_opinion_rejected', 'rejection_reason' => 'Rejected by second opinion: Wrong series.'],
    ]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->click(sprintf('[data-action-row="%d"]', $actionRequest->id))
        ->assertAttribute('[data-action-second-opinion]', 'data-second-opinion-state', 'disagrees')
        ->assertSeeIn('[data-action-second-opinion]', 'Wrong series.')
        ->assertMissing('[data-action-rejection-reason]')
        ->assertMissing('[data-action-approved-by]')
        ->assertMissing('[data-action-rejected-by]');
});

test('a request a reviewer rejected keeps its rejection reason and drops approved by', function (): void {
    $actionRequest = ActionRequest::factory()->create([
        'origin' => 'agent',
        'status' => ActionRequestStatus::Rejected,
        'result' => ['success' => false, 'rejection_reason' => 'Not this season.'],
    ]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->click(sprintf('[data-action-row="%d"]', $actionRequest->id))
        ->assertSeeIn('[data-action-rejection-reason]', 'Not this season.')
        ->assertMissing('[data-action-approved-by]')
        ->assertMissing('[data-action-rejected-by]');
});

test('a request a reviewer rejected shows who rejected it', function (): void {
    $reviewer = User::factory()->admin()->create(['name' => 'Rhea Reviewer']);
    $actionRequest = ActionRequest::factory()->create([
        'origin' => 'agent',
        'status' => ActionRequestStatus::Rejected,
        'approved_by' => $reviewer->id,
        'result' => ['success' => false, 'rejection_reason' => 'Not this season.'],
    ]);
    $this->actingAs($reviewer);

    visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->click(sprintf('[data-action-row="%d"]', $actionRequest->id))
        ->assertSeeIn('[data-action-rejected-by]', 'Rejected by')
        ->assertSeeIn('[data-action-rejected-by]', 'Rhea Reviewer')
        ->assertMissing('[data-action-approved-by]');
});

test('a request that is not rejected shows who approved it', function (): void {
    $actionRequest = ActionRequest::factory()->create(['origin' => 'agent', 'status' => ActionRequestStatus::Completed, 'requires_approval' => false]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->click(sprintf('[data-action-row="%d"]', $actionRequest->id))
        ->assertSeeIn('[data-action-approved-by]', 'system');
});

test('the card says when a per-event rule decided the second opinion', function (): void {
    $actionRequest = ActionRequest::factory()->secondOpinion(agrees: false, reason: 'Checked the event.', eventKey: 'sonarr:Download')->create([
        'origin' => 'agent', 'status' => ActionRequestStatus::Pending, 'requires_approval' => true,
    ]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->click(sprintf('[data-action-row="%d"]', $actionRequest->id))
        ->assertSeeIn('[data-action-second-opinion] [data-second-opinion-override-event]', 'Per-event rule: sonarr:Download');
});

test('the approval rules page says per-event overrides take precedence', function (): void {
    ActionTypeConfig::factory()->create(['type' => 'monitor_series', 'label' => 'Monitor series']);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('actions.rules.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-second-opinion-event-note]', 'Per-event overrides on the Decision Agent page take precedence.')
        ->assertAttribute('[data-second-opinion-event-note-link]', 'href', route('admin.decision-agent.index', absolute: false));
});

test('the card says when a per-event rule skipped the second opinion and offers no benchmark', function (): void {
    config()->set('mediamanager.ai.enabled', true);
    $actionRequest = ActionRequest::factory()->described()->secondOpinionSkipped('sonarr:Download')->create([
        'origin' => 'agent', 'status' => ActionRequestStatus::Pending, 'requires_approval' => true,
    ]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('actions.requests.index', absolute: false))
        ->assertNoSmoke()
        ->click(sprintf('[data-action-row="%d"]', $actionRequest->id))
        ->assertAttribute('[data-action-second-opinion]', 'data-second-opinion-state', 'skipped')
        ->assertSeeIn('[data-action-second-opinion]', 'Second opinion skipped')
        ->assertSeeIn('[data-action-second-opinion] [data-second-opinion-override-event]', 'Per-event rule: sonarr:Download')
        ->assertMissing('[data-action-benchmark-verdict]');
});
