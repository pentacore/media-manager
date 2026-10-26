<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\User;

/**
 * @param  array<string, mixed>  $attributes
 */
function agreementBrowserReviewed(bool $agrees, bool $approved, array $attributes = [], string $reason = 'Looks right.'): ActionRequest
{
    return ActionRequest::factory()->secondOpinion($agrees, reason: $reason)->create([
        'status' => $approved ? ActionRequestStatus::Completed : ActionRequestStatus::Rejected,
        'approved_by' => User::factory()->admin(),
        ...$attributes,
    ]);
}

test('the second opinion panel shows agreement with reviewers', function (): void {
    agreementBrowserReviewed(true, true);
    agreementBrowserReviewed(true, true);
    agreementBrowserReviewed(true, true);
    agreementBrowserReviewed(false, false);
    agreementBrowserReviewed(false, true);
    agreementBrowserReviewed(true, false);
    ActionRequest::factory()->secondOpinion(false, failed: true, reason: 'Skipped: the decision run is out of time.')->create();
    ActionRequest::factory()->secondOpinion(false, mode: 'reject')->create([
        'status' => ActionRequestStatus::Rejected,
        'requires_approval' => false,
        'result' => ['success' => false, 'reason' => 'second_opinion_rejected', 'rejection_reason' => 'Rejected by second opinion: Wrong series.'],
    ]);
    ActionRequest::factory()->secondOpinion(false)->create();
    ActionRequest::factory()->secondOpinionSkipped('sonarr:Download')->create();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-usage.index', absolute: false))
        ->assertNoSmoke()
        ->assertVisible('[data-second-opinion-agreement]')
        ->assertSeeIn('[data-agreement-stat="agreement"]', '67%')
        ->assertSeeIn('[data-agreement-stat="agreement"]', 'of 6 reviewed')
        ->assertSeeIn('[data-agreement-stat="holds_right"]', '50%')
        ->assertSeeIn('[data-agreement-stat="holds_right"]', 'of 2 reviewed holds')
        ->assertSeeIn('[data-agreement-stat="false_alarms"]', '1')
        ->assertSeeIn('[data-agreement-stat="misses"]', '1')
        ->assertSeeIn('[data-agreement-stat="coverage"]', 'of 9 verdicts')
        ->assertSeeIn('[data-agreement-counts]', '9 verdicts · 1 failed · 1 rejected by the verifier · 1 awaiting review · 1 skipped by a per-event rule')
        ->assertSeeIn('[data-agreement-cell="agreed_approved"]', '3')
        ->assertSeeIn('[data-agreement-cell="agreed_rejected"]', '1')
        ->assertSeeIn('[data-agreement-cell="disagreed_approved"]', '1')
        ->assertSeeIn('[data-agreement-cell="disagreed_rejected"]', '1')
        ->assertAttribute('[data-agreement-tune]', 'href', route('admin.decision-agent.index', absolute: false));
});

test('the second opinion panel says when the window has no verdicts', function (): void {
    ActionRequest::factory()->secondOpinionSkipped('sonarr:Download')->create();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-usage.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-agreement-empty]', 'No second opinions in this window.')
        ->assertMissing('[data-agreement-stats]');
});
