<?php

declare(strict_types=1);

namespace App\Services\AiUsage;

use App\Ai\Decision\SecondOpinionGroundTruth;
use App\Ai\Decision\SecondOpinionPolicy;
use App\Ai\Decision\SecondOpinionVerdict;
use App\Ai\TaskModelResolver;
use App\Enums\ActionRequestStatus;
use App\Enums\AiTask;
use App\Enums\SecondOpinionMode;
use App\Models\ActionRequest;
use App\Models\ActionTypeConfig;
use App\Models\AiTaskModel;
use App\Settings\DecisionAgentSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * How often the people who reviewed an action agreed with its second
 * opinion, for the AI Usage page. The ground truth is
 * SecondOpinionGroundTruth: reject-mode rejections and auto-run actions are
 * never reviewed, so they are counted apart and never scored.
 *
 * @phpstan-type BreakdownRow array{key: string, label: string, verdicts: int, reviewed: int, agreement: float|null, false_alarms: int, misses: int}
 * @phpstan-type Group array{key: string, label: string, verdicts: int, reviewed: int, right: int, false_alarms: int, misses: int}
 */
final readonly class SecondOpinionAgreementReporting
{
    private const int BREAKDOWN_ROWS = 20;

    private const int DISAGREEMENTS = 10;

    private const int DAILY_MAX_DAYS = 92;

    private const string UNKNOWN_EVENT = 'unknown';

    private const string OTHER = 'other';

    public function __construct(private DecisionAgentSettings $decisionAgentSettings, private TaskModelResolver $taskModelResolver) {}

    /**
     * @return array{
     *     totals: array{verdicts: int, reviewed: int, agreed_approved: int, agreed_rejected: int, disagreed_rejected: int, disagreed_approved: int, failed: int, rejected_by_verifier: int, awaiting_review: int, auto_run: int, unattributed: int, skipped: int},
     *     rates: array{agreement: float|null, holds_right: float|null, coverage: float|null},
     *     by_event: list<BreakdownRow>,
     *     by_action_type: list<BreakdownRow>,
     *     by_answered_by: list<BreakdownRow>,
     *     daily_bucket: 'day'|'week',
     *     daily: list<array{date: string, reviewed: int, agreement: float|null}>,
     *     disagreements: list<array{id: int, title: string|null, type: string, status: string, event_key: string|null, kind: 'false_alarm'|'miss', reason: string|null, created_at: string}>,
     *     overridden_events: list<string>
     * }
     */
    public function summary(?CarbonImmutable $since): array
    {
        $totals = [
            'verdicts' => 0, 'reviewed' => 0, 'agreed_approved' => 0, 'agreed_rejected' => 0, 'disagreed_rejected' => 0, 'disagreed_approved' => 0,
            'failed' => 0, 'rejected_by_verifier' => 0, 'awaiting_review' => 0, 'auto_run' => 0, 'unattributed' => 0, 'skipped' => 0,
        ];

        /** @var array<string, Group> $events */
        $events = [];
        /** @var array<string, Group> $types */
        $types = [];
        /** @var array<string, Group> $answeredBy */
        $answeredBy = [];
        /** @var array<string, array{reviewed: int, right: int}> $days */
        $days = [];
        $disagreements = [];
        $earliest = null;

        foreach ($this->verdictQuery($since)->toBase()->get() as $row) {
            /** @var array<string, mixed>|null $secondOpinion */
            $secondOpinion = is_string($row->second_opinion) ? json_decode($row->second_opinion, true) : null;
            $secondOpinion = is_array($secondOpinion) ? $secondOpinion : null;

            if (SecondOpinionPolicy::isSkipMarker($secondOpinion)) {
                $totals['skipped']++;

                continue;
            }

            $status = ActionRequestStatus::tryFrom((string) $row->status);

            if (! is_array($secondOpinion) || ! is_bool($secondOpinion['agrees'] ?? null) || ! $status instanceof ActionRequestStatus) {
                continue;
            }

            $approvedBy = $row->approved_by === null ? null : (int) $row->approved_by;
            $bucket = $this->bucket($secondOpinion, $status, $approvedBy, (bool) $row->requires_approval);
            $createdAt = CarbonImmutable::parse((string) $row->created_at);

            $totals['verdicts']++;
            $totals[$bucket]++;
            $earliest = $earliest instanceof CarbonImmutable ? $earliest->min($createdAt) : $createdAt;

            $reviewed = in_array($bucket, ['agreed_approved', 'agreed_rejected', 'disagreed_rejected', 'disagreed_approved'], true);
            $right = in_array($bucket, ['agreed_approved', 'disagreed_rejected'], true);
            $falseAlarm = $bucket === 'disagreed_approved';
            $miss = $bucket === 'agreed_rejected';

            $eventKey = $this->eventKey($secondOpinion, $row->service_type ?? null, $row->event_type ?? null);
            $type = (string) $row->type;

            [$answeredByKey, $answeredByLabel] = $this->answeredBy($secondOpinion);

            $this->tally($events, $eventKey ?? self::UNKNOWN_EVENT, $eventKey ?? 'Unknown event', $reviewed, $right, $falseAlarm, $miss);
            $this->tally($types, $type, $type, $reviewed, $right, $falseAlarm, $miss);
            $this->tally($answeredBy, $answeredByKey, $answeredByLabel, $reviewed, $right, $falseAlarm, $miss);

            if ($reviewed) {
                $day = $createdAt->toDateString();
                $days[$day] ??= ['reviewed' => 0, 'right' => 0];
                $days[$day]['reviewed']++;
                $days[$day]['right'] += (int) $right;
            }

            if (($falseAlarm || $miss) && count($disagreements) < self::DISAGREEMENTS) {
                $disagreements[] = [
                    'id' => (int) $row->id,
                    'title' => $row->title === null ? null : (string) $row->title,
                    'type' => $type,
                    'status' => $status->value,
                    'event_key' => $eventKey,
                    'kind' => $falseAlarm ? 'false_alarm' : 'miss',
                    'reason' => is_string($secondOpinion['reason'] ?? null) ? $secondOpinion['reason'] : null,
                    'created_at' => $createdAt->toIso8601String(),
                ];
            }
        }

        $totals['reviewed'] = $totals['agreed_approved'] + $totals['agreed_rejected'] + $totals['disagreed_rejected'] + $totals['disagreed_approved'];

        $labels = ActionTypeConfig::query()->whereIn('type', array_keys($types))->pluck('label', 'type');

        foreach ($types as $key => $group) {
            $types[$key]['label'] = (string) ($labels[$key] ?? $key);
        }

        $series = $this->series($days, $since, $earliest);

        return [
            'totals' => $totals,
            'rates' => [
                'agreement' => $this->rate($totals['agreed_approved'] + $totals['disagreed_rejected'], $totals['reviewed']),
                'holds_right' => $this->rate($totals['disagreed_rejected'], $totals['disagreed_rejected'] + $totals['disagreed_approved']),
                'coverage' => $this->rate($totals['reviewed'], $totals['verdicts']),
            ],
            'by_event' => $this->breakdown($events),
            'by_action_type' => $this->breakdown($types),
            'by_answered_by' => $this->breakdown($answeredBy),
            'daily_bucket' => $series['bucket'],
            'daily' => $series['points'],
            'disagreements' => $disagreements,
            'overridden_events' => $this->overriddenEvents(),
        ];
    }

    /**
     * @return Builder<ActionRequest>
     */
    private function verdictQuery(?CarbonImmutable $since): Builder
    {
        return ActionRequest::query()
            ->leftJoin('webhook_events', 'webhook_events.id', '=', 'action_requests.webhook_event_id')
            ->leftJoin('service_connections', 'service_connections.id', '=', 'webhook_events.service_connection_id')
            ->whereNotNull('action_requests.second_opinion')
            ->when($since instanceof CarbonImmutable, fn (Builder $builder): Builder => $builder->where('action_requests.created_at', '>=', $since))
            ->select(['action_requests.id', 'action_requests.title', 'action_requests.type', 'action_requests.status', 'action_requests.requires_approval', 'action_requests.approved_by', 'action_requests.second_opinion', 'action_requests.created_at', 'webhook_events.event_type', 'service_connections.type as service_type'])
            ->latest('action_requests.created_at')
            ->orderByDesc('action_requests.id');
    }

    /**
     * @param  array<string, mixed>  $secondOpinion
     */
    private function bucket(array $secondOpinion, ActionRequestStatus $status, ?int $approvedBy, bool $requiresApproval): string
    {
        if (($secondOpinion['failed'] ?? false) === true) {
            return 'failed';
        }

        $agrees = $secondOpinion['agrees'] === true;
        $expectedAgrees = SecondOpinionGroundTruth::expectedAgrees($status, $approvedBy, $secondOpinion);

        if ($expectedAgrees !== null) {
            return match (true) {
                $agrees && $expectedAgrees => 'agreed_approved',
                $agrees => 'agreed_rejected',
                $expectedAgrees => 'disagreed_approved',
                default => 'disagreed_rejected',
            };
        }

        return match (true) {
            $status === ActionRequestStatus::Pending => 'awaiting_review',
            $status === ActionRequestStatus::Rejected && ! $agrees && $approvedBy === null && ($secondOpinion['mode'] ?? null) === SecondOpinionMode::Reject->value => 'rejected_by_verifier',
            $agrees && $approvedBy === null && ! $requiresApproval && $status !== ActionRequestStatus::Rejected => 'auto_run',
            default => 'unattributed',
        };
    }

    /**
     * Counts one verdict towards its group, opening the group on first sight.
     *
     * @param  array<string, Group>  $groups
     */
    private function tally(array &$groups, string $key, string $label, bool $reviewed, bool $right, bool $falseAlarm, bool $miss): void
    {
        $groups[$key] ??= ['key' => $key, 'label' => $label, 'verdicts' => 0, 'reviewed' => 0, 'right' => 0, 'false_alarms' => 0, 'misses' => 0];
        $groups[$key]['verdicts']++;
        $groups[$key]['reviewed'] += (int) $reviewed;
        $groups[$key]['right'] += (int) $right;
        $groups[$key]['false_alarms'] += (int) $falseAlarm;
        $groups[$key]['misses'] += (int) $miss;
    }

    /**
     * @param  array<string, mixed>  $secondOpinion
     */
    private function eventKey(array $secondOpinion, mixed $serviceType, mixed $eventType): ?string
    {
        $stored = $secondOpinion['event_key'] ?? null;

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        if (is_string($serviceType) && is_string($eventType)) {
            return DecisionAgentSettings::eventKey($serviceType, $eventType);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $secondOpinion
     * @return array{0: string, 1: string}
     */
    private function answeredBy(array $secondOpinion): array
    {
        $engine = $secondOpinion['engine'] ?? null;

        if ($engine === SecondOpinionVerdict::ENGINE_JEV) {
            return ['jev', 'Jev'];
        }

        $provider = filled($secondOpinion['provider'] ?? null) && is_string($secondOpinion['provider']) ? $secondOpinion['provider'] : null;
        $model = filled($secondOpinion['model'] ?? null) && is_string($secondOpinion['model']) ? $secondOpinion['model'] : null;

        if ($engine === SecondOpinionVerdict::ENGINE_LLM && ($provider !== null || $model !== null)) {
            return [sprintf('llm:%s/%s', $provider ?? '', $model ?? ''), implode(' · ', array_filter([$provider, $model]))];
        }

        return ['unknown', 'Unknown'];
    }

    /**
     * @param  array<string, Group>  $groups
     * @return list<BreakdownRow>
     */
    private function breakdown(array $groups): array
    {
        $sorted = array_values($groups);
        usort($sorted, fn (array $a, array $b): int => $b['verdicts'] <=> $a['verdicts'] ?: strnatcasecmp($a['label'], $b['label']));

        if (count($sorted) > self::BREAKDOWN_ROWS) {
            $rest = array_slice($sorted, self::BREAKDOWN_ROWS - 1);
            $sorted = array_slice($sorted, 0, self::BREAKDOWN_ROWS - 1);
            $other = ['key' => self::OTHER, 'label' => 'Other', 'verdicts' => 0, 'reviewed' => 0, 'right' => 0, 'false_alarms' => 0, 'misses' => 0];

            foreach ($rest as $group) {
                foreach (['verdicts', 'reviewed', 'right', 'false_alarms', 'misses'] as $field) {
                    $other[$field] += $group[$field];
                }
            }

            $sorted[] = $other;
        }

        return array_map(fn (array $group): array => [
            'key' => $group['key'],
            'label' => $group['label'],
            'verdicts' => $group['verdicts'],
            'reviewed' => $group['reviewed'],
            'agreement' => $this->rate($group['right'], $group['reviewed']),
            'false_alarms' => $group['false_alarms'],
            'misses' => $group['misses'],
        ], $sorted);
    }

    /**
     * @param  array<string, array{reviewed: int, right: int}>  $days
     * @return array{bucket: 'day'|'week', points: list<array{date: string, reviewed: int, agreement: float|null}>}
     */
    private function series(array $days, ?CarbonImmutable $since, ?CarbonImmutable $earliest): array
    {
        $start = ($since ?? $earliest)?->startOfDay();

        if (! $start instanceof CarbonImmutable) {
            return ['bucket' => 'day', 'points' => []];
        }

        $today = today();
        $weekly = (int) $start->diffInDays($today) + 1 > self::DAILY_MAX_DAYS;

        if ($weekly) {
            $weeks = [];

            foreach ($days as $date => $tally) {
                $monday = CarbonImmutable::parse($date)->startOfWeek(CarbonInterface::MONDAY)->toDateString();
                $weeks[$monday] ??= ['reviewed' => 0, 'right' => 0];
                $weeks[$monday]['reviewed'] += $tally['reviewed'];
                $weeks[$monday]['right'] += $tally['right'];
            }

            $days = $weeks;
            $start = $start->startOfWeek(CarbonInterface::MONDAY);
        }

        $points = [];

        for ($cursor = $start; $cursor <= $today; $cursor = $weekly ? $cursor->addWeek() : $cursor->addDay()) {
            $tally = $days[$cursor->toDateString()] ?? ['reviewed' => 0, 'right' => 0];

            $points[] = ['date' => $cursor->toDateString(), 'reviewed' => $tally['reviewed'], 'agreement' => $this->rate($tally['right'], $tally['reviewed'])];
        }

        return ['bucket' => $weekly ? 'week' : 'day', 'points' => $points];
    }

    /**
     * @return list<string>
     */
    private function overriddenEvents(): array
    {
        $events = array_keys($this->decisionAgentSettings->secondOpinionEventOverrides());

        foreach ($this->taskModelResolver->scopes(AiTask::DecisionVerifier) as $scope) {
            if ($scope === AiTaskModel::DEFAULT_SCOPE) {
                continue;
            }

            $tookEffect = $this->taskModelResolver->tiers(AiTask::DecisionVerifier, $scope)
                ->contains(fn (AiTaskModel $aiTaskModel): bool => filled($aiTaskModel->provider) || filled($aiTaskModel->model) || $aiTaskModel->reasoning !== null);

            if ($tookEffect) {
                $events[] = $scope;
            }
        }

        $events = array_values(array_unique(array_map(strval(...), $events)));
        sort($events);

        return $events;
    }

    private function rate(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole, 4) : null;
    }
}
