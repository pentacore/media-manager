<?php

declare(strict_types=1);

use App\Enums\ActionRequestStatus;
use App\Enums\AiTask;
use App\Enums\SecondOpinionMode;
use App\Models\ActionRequest;
use App\Models\AiTaskModel;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\AiUsage\SecondOpinionAgreementReporting;
use App\Settings\DecisionAgentSettings;
use Carbon\CarbonImmutable;
use Database\Seeders\ActionTypeConfigSeeder;

/**
 * A verdict a person reviewed: approved ends Completed, rejected ends Rejected.
 *
 * @param  array<string, mixed>  $attributes
 */
function agreementReviewed(bool $agrees, bool $approved, array $attributes = []): ActionRequest
{
    return ActionRequest::factory()->secondOpinion($agrees)->create([
        'status' => $approved ? ActionRequestStatus::Completed : ActionRequestStatus::Rejected,
        'approved_by' => User::factory()->admin(),
        ...$attributes,
    ]);
}

/**
 * A stored LLM verdict, for rows the factory states cannot shape.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function agreementVerdict(array $overrides = []): array
{
    return [...['engine' => 'llm', 'mode' => 'hold', 'event_key' => null, 'agrees' => true, 'failed' => false, 'confidence' => null, 'reason' => 'Looks right.', 'provider' => 'openai', 'model' => 'gpt-5-mini', 'invocation_id' => null], ...$overrides];
}

/**
 * @return array<string, mixed>
 */
function agreementSummary(?CarbonImmutable $since = null): array
{
    return resolve(SecondOpinionAgreementReporting::class)->summary($since);
}

/**
 * @param  list<array<string, mixed>>  $rows
 * @return array<string, array<string, mixed>>
 */
function agreementRowsByKey(array $rows): array
{
    return collect($rows)->keyBy('key')->sortKeys()->all();
}

test('every verdict lands in exactly one bucket', function (): void {
    foreach ([ActionRequestStatus::Completed, ActionRequestStatus::Approved, ActionRequestStatus::Failed] as $status) {
        agreementReviewed(true, true, ['status' => $status]);
    }

    agreementReviewed(true, false);
    agreementReviewed(false, false);
    agreementReviewed(false, false);
    agreementReviewed(false, true);

    ActionRequest::factory()->secondOpinion(false, failed: true, reason: 'Skipped: the decision run is out of time.')->create();
    agreementReviewed(false, true, ['second_opinion' => agreementVerdict(['agrees' => false, 'failed' => true])]);

    ActionRequest::factory()->secondOpinion(false, mode: 'reject')->create([
        'status' => ActionRequestStatus::Rejected,
        'requires_approval' => false,
        'result' => ['success' => false, 'reason' => 'second_opinion_rejected', 'rejection_reason' => 'Rejected by second opinion: Wrong series.'],
    ]);

    ActionRequest::factory()->secondOpinion(false)->create();
    ActionRequest::factory()->secondOpinion(true)->create();
    ActionRequest::factory()->secondOpinion(true)->autoExecute()->completed()->create();
    ActionRequest::factory()->secondOpinionSkipped('sonarr:Download')->create();
    ActionRequest::factory()->create();
    agreementReviewed(true, true, ['created_at' => now()->subDays(40)]);

    $summary = agreementSummary(now()->subDays(30));

    expect($summary['totals'])->toBe([
        'verdicts' => 13, 'reviewed' => 7, 'agreed_approved' => 3, 'agreed_rejected' => 1, 'disagreed_rejected' => 2, 'disagreed_approved' => 1,
        'failed' => 2, 'rejected_by_verifier' => 1, 'awaiting_review' => 2, 'auto_run' => 1, 'unattributed' => 0, 'skipped' => 1,
    ])->and($summary['rates'])->toBe(['agreement' => 0.7143, 'holds_right' => 0.6667, 'coverage' => 0.5385])
        ->and(agreementSummary()['totals']['verdicts'])->toBe(14);
});

test('rates are null when nothing was reviewed', function (): void {
    $summary = agreementSummary(now()->subDays(7));

    expect(array_unique(array_values($summary['totals'])))->toBe([0])
        ->and($summary['rates'])->toBe(['agreement' => null, 'holds_right' => null, 'coverage' => null])
        ->and($summary['by_event'])->toBe([])
        ->and($summary['by_action_type'])->toBe([])
        ->and($summary['by_answered_by'])->toBe([])
        ->and($summary['disagreements'])->toBe([])
        ->and($summary['overridden_events'])->toBe([])
        ->and($summary['daily_bucket'])->toBe('day')
        ->and($summary['daily'])->toHaveCount(8)
        ->and(collect($summary['daily'])->every(fn (array $point): bool => $point['reviewed'] === 0 && $point['agreement'] === null))->toBeTrue()
        ->and(agreementSummary()['daily'])->toBe([]);

    ActionRequest::factory()->secondOpinion(false)->create();
    ActionRequest::factory()->secondOpinion(true)->autoExecute()->completed()->create();

    expect(agreementSummary(now()->subDays(7))['rates'])->toBe(['agreement' => null, 'holds_right' => null, 'coverage' => 0.0]);
});

test('the event comes from the stored override key, else the webhook event, else unknown', function (): void {
    $webhookEvent = WebhookEvent::factory()->for(ServiceConnection::factory()->sonarr())->create(['event_type' => 'Download']);

    ActionRequest::factory()->secondOpinion(true, eventKey: 'radarr:Grab')->create(['webhook_event_id' => $webhookEvent->id]);
    ActionRequest::factory()->secondOpinion(true)->create(['webhook_event_id' => $webhookEvent->id]);
    ActionRequest::factory()->secondOpinion(true)->create(['webhook_event_id' => null]);

    $summary = agreementSummary(now()->subDays(7));

    expect(array_map(fn (array $row): string => $row['label'], agreementRowsByKey($summary['by_event'])))
        ->toBe(['radarr:Grab' => 'radarr:Grab', 'sonarr:Download' => 'sonarr:Download', 'unknown' => 'Unknown event']);
});

test('answered by names the LLM provider and model, Jev, or unknown', function (): void {
    agreementReviewed(true, true);
    agreementReviewed(true, true, ['second_opinion' => agreementVerdict(['engine' => 'jev', 'confidence' => 0.92, 'provider' => null, 'model' => null])]);
    ActionRequest::factory()->create(['second_opinion' => agreementVerdict(['provider' => null, 'model' => null])]);

    $rows = agreementRowsByKey(agreementSummary(now()->subDays(7))['by_answered_by']);

    expect($rows)->toHaveKeys(['llm:openai/gpt-5-mini', 'jev', 'unknown'])
        ->and($rows['llm:openai/gpt-5-mini'])->toMatchArray(['label' => 'openai · gpt-5-mini', 'verdicts' => 1, 'reviewed' => 1, 'agreement' => 1.0])
        ->and($rows['jev'])->toMatchArray(['label' => 'Jev', 'reviewed' => 1])
        ->and($rows['unknown'])->toMatchArray(['label' => 'Unknown', 'reviewed' => 0, 'agreement' => null]);
});

test('a breakdown row carries its sample size, agreement, false alarms and misses', function (): void {
    $this->seed(ActionTypeConfigSeeder::class);

    agreementReviewed(true, true);
    agreementReviewed(true, true);
    agreementReviewed(false, true);
    agreementReviewed(true, false);
    ActionRequest::factory()->secondOpinion(true)->create();

    expect(agreementSummary(now()->subDays(7))['by_action_type'])->toBe([
        ['key' => 'delete_series', 'label' => 'Delete series from Sonarr', 'verdicts' => 5, 'reviewed' => 4, 'agreement' => 0.5, 'false_alarms' => 1, 'misses' => 1],
    ]);
});

test('breakdowns keep the 19 largest groups and fold the rest into Other', function (): void {
    ActionRequest::factory()->secondOpinion(true)->count(3)->create(['type' => 'type_01']);

    foreach (range(2, 22) as $number) {
        ActionRequest::factory()->secondOpinion(true)->create(['type' => sprintf('type_%02d', $number)]);
    }

    $rows = agreementSummary(now()->subDays(7))['by_action_type'];

    expect($rows)->toHaveCount(20)
        ->and($rows[0]['key'])->toBe('type_01')
        ->and($rows[18]['key'])->toBe('type_19')
        ->and($rows[19])->toBe(['key' => 'other', 'label' => 'Other', 'verdicts' => 3, 'reviewed' => 0, 'agreement' => null, 'false_alarms' => 0, 'misses' => 0]);
});

test('agreement per day covers every day of the window', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00'));

    agreementReviewed(true, true, ['created_at' => '2026-10-24 10:00:00']);
    agreementReviewed(false, true, ['created_at' => '2026-10-24 11:00:00']);
    agreementReviewed(true, true, ['created_at' => '2026-10-26 09:00:00']);

    $summary = agreementSummary(now()->subDays(7));

    expect($summary['daily_bucket'])->toBe('day')
        ->and($summary['daily'])->toHaveCount(8)
        ->and($summary['daily'][0]['date'])->toBe('2026-10-19')
        ->and($summary['daily'][5])->toBe(['date' => '2026-10-24', 'reviewed' => 2, 'agreement' => 0.5])
        ->and($summary['daily'][6])->toBe(['date' => '2026-10-25', 'reviewed' => 0, 'agreement' => null])
        ->and($summary['daily'][7])->toBe(['date' => '2026-10-26', 'reviewed' => 1, 'agreement' => 1.0]);
});

test('a window longer than 92 days charts agreement by week', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00'));

    agreementReviewed(true, true, ['created_at' => '2026-06-03 10:00:00']);
    agreementReviewed(false, true, ['created_at' => '2026-10-26 09:00:00']);

    $summary = agreementSummary();

    expect($summary['daily_bucket'])->toBe('week')
        ->and($summary['daily'])->toHaveCount(22)
        ->and($summary['daily'][0])->toBe(['date' => '2026-06-01', 'reviewed' => 1, 'agreement' => 1.0])
        ->and($summary['daily'][21])->toBe(['date' => '2026-10-26', 'reviewed' => 1, 'agreement' => 0.0]);
});

test('the latest ten reviewed disagreements list false alarms and misses newest first', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00'));

    $rows = [];

    foreach (range(1, 12) as $minute) {
        $rows[] = agreementReviewed($minute % 2 === 0, $minute % 2 !== 0, [
            'created_at' => now()->subMinutes(60 - $minute),
            'webhook_event_id' => null,
            'title' => sprintf('Delete show %d from Sonarr', $minute),
        ]);
    }

    agreementReviewed(true, true);
    ActionRequest::factory()->secondOpinion(false)->create();

    $disagreements = agreementSummary(now()->subDays(7))['disagreements'];
    $newest = $rows[11];
    $expectedIds = array_map(fn (ActionRequest $actionRequest): int => $actionRequest->id, array_slice(array_reverse($rows), 0, 10));

    expect($disagreements)->toHaveCount(10)
        ->and(array_map(fn (array $row): int => $row['id'], $disagreements))->toBe($expectedIds)
        ->and($disagreements[0])->toBe(['id' => $newest->id, 'title' => 'Delete show 12 from Sonarr', 'type' => 'delete_series', 'status' => 'rejected', 'event_key' => null, 'kind' => 'miss', 'reason' => 'Looks right.', 'created_at' => $newest->created_at->toIso8601String()])
        ->and($disagreements[1]['kind'])->toBe('false_alarm');
});

test('overridden events list per-event rules and event-scoped verifier tiers', function (): void {
    resolve(DecisionAgentSettings::class)->setSecondOpinionEventOverrides(['sonarr:Download' => ['mode' => SecondOpinionMode::Reject, 'engine' => null, 'jev_threshold' => null]]);

    AiTaskModel::factory()->event('radarr:Grab', AiTask::DecisionVerifier)->selecting('openai', 'gpt-5')->create();
    AiTaskModel::factory()->event('sonarr:Grab', AiTask::DecisionVerifier)->create();
    AiTaskModel::factory()->event('emby:PlaybackStart', AiTask::Decision)->selecting('openai', 'gpt-5')->create();

    expect(agreementSummary(now()->subDays(7))['overridden_events'])->toBe(['radarr:Grab', 'sonarr:Download']);
});

test('malformed second opinions are ignored', function (): void {
    ActionRequest::factory()->create(['second_opinion' => ['agrees' => 'true']]);
    ActionRequest::factory()->create(['second_opinion' => ['engine' => 'llm', 'reason' => 'x']]);
    agreementReviewed(true, true);

    $summary = agreementSummary(now()->subDays(7));

    expect($summary['totals']['verdicts'])->toBe(1)
        ->and($summary['rates']['agreement'])->toBe(1.0);
});

test('a review whose reviewer was deleted is no longer scored', function (): void {
    $reviewer = User::factory()->admin()->create();

    agreementReviewed(true, true, ['approved_by' => $reviewer->id]);
    agreementReviewed(false, true, ['approved_by' => $reviewer->id]);
    agreementReviewed(false, false, ['approved_by' => $reviewer->id]);

    $reviewer->delete();

    $summary = agreementSummary(now()->subDays(7));

    expect($summary['totals']['reviewed'])->toBe(0)
        ->and($summary['totals']['auto_run'])->toBe(0)
        ->and($summary['totals']['rejected_by_verifier'])->toBe(0)
        ->and($summary['totals']['unattributed'])->toBe(3)
        ->and($summary['totals']['verdicts'])->toBe(3)
        ->and($summary['rates']['agreement'])->toBeNull();
});
