<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Enums\SecondOpinionMode;
use App\Models\ActionRequest;
use App\Models\User;
use App\Settings\DecisionAgentSettings;
use Database\Seeders\ActionTypeConfigSeeder;

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
    ActionRequest::factory()->secondOpinion(true)->autoExecute()->completed()->create();
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
        ->assertAttributeContains('[data-agreement-stat="false_alarms"]', 'class', 'text-warning')
        ->assertSeeIn('[data-agreement-stat="misses"]', '1')
        ->assertAttributeContains('[data-agreement-stat="misses"]', 'class', 'text-destructive')
        ->assertSeeIn('[data-agreement-stat="coverage"]', 'of 10 verdicts')
        ->assertSeeIn('[data-agreement-counts]', '10 verdicts · 1 failed · 1 rejected by the verifier · 1 awaiting review · 1 ran automatically · 1 skipped by a per-event rule')
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

test('the breakdowns mark per-event rules and mute small samples', function (): void {
    $this->seed(ActionTypeConfigSeeder::class);
    resolve(DecisionAgentSettings::class)->setSecondOpinionEventOverrides(['sonarr:Download' => ['mode' => SecondOpinionMode::Reject, 'engine' => null, 'jev_threshold' => null]]);
    foreach (range(1, 5) as $ignored) {
        ActionRequest::factory()->secondOpinion(true, eventKey: 'sonarr:Download')->create(['status' => ActionRequestStatus::Completed, 'approved_by' => User::factory()->admin()]);
    }

    ActionRequest::factory()->secondOpinion(true, eventKey: 'radarr:Grab')->create(['status' => ActionRequestStatus::Completed, 'approved_by' => User::factory()->admin()]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-usage.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-agreement-breakdown="event"] [data-breakdown-row="sonarr:Download"]', 'per-event rule')
        ->assertAttribute('[data-agreement-breakdown="event"] [data-breakdown-row="sonarr:Download"] [data-breakdown-agreement]', 'data-low-sample', 'false')
        ->assertAttribute('[data-agreement-breakdown="event"] [data-breakdown-row="radarr:Grab"] [data-breakdown-agreement]', 'data-low-sample', 'true')
        ->assertMissing('[data-agreement-breakdown="event"] [data-breakdown-row="radarr:Grab"] [data-breakdown-override]')
        ->assertAttribute('[data-agreement-breakdown-tab="event"]', 'aria-pressed', 'true')
        ->assertMissing('[data-agreement-breakdown="action_type"]')
        ->click('[data-agreement-breakdown-tab="action_type"]')
        ->assertSeeIn('[data-agreement-breakdown="action_type"] [data-breakdown-row="delete_series"]', 'Delete series from Sonarr')
        ->assertMissing('[data-agreement-breakdown="event"]')
        ->click('[data-agreement-breakdown-tab="answered_by"]')
        ->assertSeeIn('[data-agreement-breakdown="answered_by"] [data-breakdown-row="llm:openai/gpt-5-mini"]', 'openai · gpt-5-mini')
        ->assertMissing('[data-agreement-breakdown="action_type"]')
        ->assertNoSmoke();
});

test('recent disagreements link to the request and to benchmark capture', function (): void {
    $actionRequest = agreementBrowserReviewed(false, true, ['title' => 'Delete Example Show from Sonarr'], 'The series is still airing.');
    $miss = agreementBrowserReviewed(true, false, ['title' => 'Delete Another Show from Sonarr']);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-usage.index', absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn("[data-disagreement=\"{$actionRequest->id}\"] [data-disagreement-kind]", 'False alarm')
        ->assertSeeIn("[data-disagreement=\"{$actionRequest->id}\"] [data-disagreement-reason]", 'The series is still airing.')
        ->assertSeeIn("[data-disagreement=\"{$miss->id}\"] [data-disagreement-kind]", 'Miss')
        ->assertAttribute("[data-disagreement=\"{$actionRequest->id}\"] [data-disagreement-request]", 'href', route('actions.requests.index', ['status' => 'completed', 'request' => $actionRequest->id], absolute: false))
        ->assertAttribute("[data-disagreement=\"{$miss->id}\"] [data-disagreement-request]", 'href', route('actions.requests.index', ['status' => 'rejected', 'request' => $miss->id], absolute: false))
        ->assertAttribute("[data-disagreement=\"{$miss->id}\"] [data-disagreement-benchmark]", 'href', route('admin.benchmarks.cases.create', ['action_request' => $miss->id], absolute: false));
});

test('opening an older disagreement shows its request in the Action Queue', function (): void {
    $actionRequest = agreementBrowserReviewed(false, true, ['title' => 'Delete Example Old Show from Sonarr', 'created_at' => now()->subDay()]);
    ActionRequest::factory()->count(30)->completed()->create();
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-usage.index', absolute: false))
        ->assertNoSmoke()
        ->click("[data-disagreement=\"{$actionRequest->id}\"] [data-disagreement-request]")
        ->assertSeeIn('[data-action-title]', 'Delete Example Old Show from Sonarr')
        ->assertQueryStringHas('request', (string) $actionRequest->id)
        ->assertNoSmoke();
});

test('agreement per day shows a placeholder bar for days nothing was reviewed', function (): void {
    agreementBrowserReviewed(true, true);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-usage.index', ['window' => '7d'], absolute: false))
        ->assertNoSmoke()
        ->assertSeeIn('[data-agreement-daily]', 'Agreement per day')
        ->assertAttribute('[data-agreement-daily] rect[data-bar-placeholder]:nth-of-type(1)', 'data-bar-placeholder', 'true')
        ->assertAttribute('[data-agreement-daily] rect[data-bar-placeholder]:nth-of-type(8)', 'data-bar-placeholder', 'false');
});

test('the second opinion panel fits a phone screen', function (): void {
    agreementBrowserReviewed(false, true, [
        'title' => str_repeat('Delete A Very Long Series Title From Sonarr ', 6),
        'second_opinion' => ['engine' => 'llm', 'mode' => 'hold', 'event_key' => 'sonarr:'.str_repeat('VeryLongEventName', 6), 'agrees' => false, 'failed' => false, 'confidence' => null, 'reason' => str_repeat('The series is still airing and has unwatched episodes. ', 8), 'provider' => 'openai', 'model' => 'gpt-5-mini', 'invocation_id' => null],
    ]);
    $this->actingAs(User::factory()->admin()->create());

    visit(route('admin.ai-usage.index', absolute: false))->on()->mobile()
        ->assertNoSmoke()
        ->assertVisible('[data-agreement-disagreements]')
        ->assertScript(phoneOverflowScript(), '');
});
