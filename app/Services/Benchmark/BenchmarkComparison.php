<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Enums\BenchmarkJudgeStatus;
use App\Enums\BenchmarkResultStatus;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkNeutralJudgement;
use App\Models\BenchmarkResult;
use App\Models\BenchmarkRun;
use Illuminate\Support\Collection;

/**
 * Lines up 2–5 benchmark runs: one column per run (the baseline first), one
 * line per model followed across the runs (identical selections share a line;
 * manual pairs merge two lines), and per task the figures each line earned on
 * the cases common to all runs, their deltas against the baseline and the
 * case grid. In a neutral score mode, judge-scored results take the neutral
 * judge's score and verdict instead of their own; deterministic cases keep
 * theirs and costs are always the stored ones.
 */
final readonly class BenchmarkComparison
{
    public const string ORIGINAL = 'original';

    /** @var list<string> */
    public const array DELTA_METRICS = ['avg_score', 'pass_rate', 'cost_per_case', 'latency_p50', 'error_rate'];

    /** One point on the judge's 10-point scale. */
    private const float SCORE_CHANGE = 0.1;

    private const float TOLERANCE = 0.000001;

    private const array RESULT_COLUMNS = ['id', 'benchmark_run_id', 'benchmark_case_id', 'case_name', 'case_task', 'candidate_index', 'status', 'cost_usd', 'judge_cost_usd', 'latency_ms', 'tool_misses', 'score', 'passed', 'judge_status'];

    /**
     * `runA.index~runB.index` entries; anything else is dropped.
     *
     * @return list<array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}}>
     */
    public static function parsePairs(?string $pairs): array
    {
        $parsed = [];

        foreach (explode(',', (string) $pairs) as $entry) {
            if (preg_match('/^(\d+)\.(\d+)~(\d+)\.(\d+)$/', trim($entry), $matches) !== 1) {
                continue;
            }

            $parsed[] = [[(int) $matches[1], (int) $matches[2]], [(int) $matches[3], (int) $matches[4]]];
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $selection
     */
    public static function candidateLabel(array $selection): string
    {
        $reasoning = AiReasoningLevel::tryFrom(is_string($selection['reasoning'] ?? null) ? $selection['reasoning'] : '');

        return implode(' · ', array_filter([
            is_string($selection['provider'] ?? null) ? $selection['provider'] : null,
            is_string($selection['model'] ?? null) ? $selection['model'] : null,
            $reasoning instanceof AiReasoningLevel && $reasoning !== AiReasoningLevel::ProviderDefault ? $reasoning->label() : null,
        ], static fn (?string $part): bool => $part !== null && $part !== ''));
    }

    /**
     * @param  list<int>  $runIds
     * @param  list<array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}}>  $pairs
     * @return array<string, mixed>
     */
    public function build(array $runIds, ?int $baselineId, array $pairs, ?string $scoreSource): array
    {
        $columns = $this->columns($runIds, $baselineId);
        [$lines, $appliedPairs] = $this->lines($columns, $pairs);
        $scope = BenchmarkComparisonScope::commonCases($columns->pluck('id')->all());
        $commonIds = collect($scope)->flatMap(static fn (array $task): array => $task['common'])->unique()->values()->all();

        $results = BenchmarkResult::query()
            ->whereIn('benchmark_run_id', $columns->pluck('id'))
            ->whereIn('benchmark_case_id', $commonIds)
            ->get(self::RESULT_COLUMNS);

        $judgedCaseIds = $this->judgedCaseIds($commonIds);
        $judgedOk = $results->filter(fn (BenchmarkResult $benchmarkResult): bool => $this->isJudgedOk($benchmarkResult, $judgedCaseIds));
        $judgements = BenchmarkNeutralJudgement::query()
            ->whereIn('benchmark_result_id', $judgedOk->pluck('id'))
            ->where('status', BenchmarkJudgeStatus::Ok)
            ->get(['benchmark_result_id', 'judge_key', 'judge', 'score', 'passed']);

        $options = $this->scoreOptions($judgements, $judgedOk->count());
        $current = collect($options)->contains('key', $scoreSource) ? (string) $scoreSource : self::ORIGINAL;
        $neutral = $current === self::ORIGINAL ? null : $judgements->where('judge_key', $current)->keyBy('benchmark_result_id');
        $neutralJudge = $current === self::ORIGINAL ? null : (array) $judgements->firstWhere('judge_key', $current)?->judge;

        $effective = $neutral instanceof Collection
            ? $results->map(fn (BenchmarkResult $benchmarkResult): BenchmarkResult => $this->withNeutral($benchmarkResult, $neutral, $judgedCaseIds))
            : $results;

        $tasks = [];

        foreach ($this->taskOrder(array_keys($scope)) as $task) {
            $tasks[] = $this->task($task, $scope[$task], $columns, $lines, $effective->where('case_task', $task), $neutralJudge);
        }

        $judgeKeys = $columns
            ->map(static fn (BenchmarkRun $benchmarkRun): ?string => is_array($benchmarkRun->judge) ? BenchmarkNeutralJudgement::keyFor($benchmarkRun->judge) : null)
            ->filter()
            ->unique();

        return [
            'runs' => $columns->map(fn (BenchmarkRun $benchmarkRun): array => [
                'id' => $benchmarkRun->id,
                'name' => $benchmarkRun->name,
                'status' => $benchmarkRun->status->value,
                'status_label' => $benchmarkRun->status->label(),
                'created_at' => $benchmarkRun->created_at?->toIso8601String(),
                'judge' => $benchmarkRun->judge,
                'judge_label' => is_array($benchmarkRun->judge) ? self::candidateLabel($benchmarkRun->judge) : null,
                'schedule_name' => $benchmarkRun->schedule?->name,
                'is_baseline' => $benchmarkRun->is($columns->first()),
                'candidates' => $benchmarkRun->candidates,
            ])->all(),
            'lines' => array_map(static fn (array $line): array => [
                'key' => $line['key'],
                'label' => $line['label'],
                'paired' => $line['paired'],
                'members' => $line['member_list'],
            ], $lines),
            'pairs' => $appliedPairs,
            'tasks' => $tasks,
            'score_source' => ['current' => $current, 'options' => $options],
            'judges_differ' => $judgeKeys->count() > 1,
        ];
    }

    /**
     * Two (or more) results side by side, scored by the current score source.
     * Results outside the compared runs are left out.
     *
     * @param  list<int>  $runIds
     * @param  list<int>  $resultIds
     * @return list<array<string, mixed>>
     */
    public function outputs(array $runIds, array $resultIds, string $scoreSource): array
    {
        $results = BenchmarkResult::query()
            ->with(['run:id,name,candidates,judge', 'benchmarkCase:id,task,scoring'])
            ->whereIn('benchmark_run_id', $runIds)
            ->whereKey($resultIds)
            ->get()
            ->sortBy(static fn (BenchmarkResult $benchmarkResult): int|false => array_search($benchmarkResult->id, $resultIds, true))
            ->values();

        $neutral = $scoreSource === self::ORIGINAL ? collect() : BenchmarkNeutralJudgement::query()
            ->whereIn('benchmark_result_id', $results->pluck('id'))
            ->where('judge_key', $scoreSource)
            ->where('status', BenchmarkJudgeStatus::Ok)
            ->get()
            ->keyBy('benchmark_result_id');

        return $results->map(function (BenchmarkResult $benchmarkResult) use ($scoreSource, $neutral): array {
            $useNeutral = $scoreSource !== self::ORIGINAL
                && $benchmarkResult->status === BenchmarkResultStatus::Ok
                && $benchmarkResult->benchmarkCase?->isJudged() === true;
            $found = $useNeutral ? $neutral->get($benchmarkResult->id) : null;
            $judgement = $found instanceof BenchmarkNeutralJudgement ? $found : null;
            $candidate = $benchmarkResult->run->candidates[$benchmarkResult->candidate_index] ?? [];
            $judge = $useNeutral ? $judgement?->judge : $benchmarkResult->run->judge;
            $detail = $useNeutral ? ($judgement instanceof BenchmarkNeutralJudgement ? $judgement->detail : []) : ($benchmarkResult->score_detail ?? []);

            return [
                'result_id' => $benchmarkResult->id,
                'run_id' => $benchmarkResult->benchmark_run_id,
                'run_name' => $benchmarkResult->run->name,
                'candidate_label' => self::candidateLabel($candidate),
                'status' => $benchmarkResult->status->value,
                'output' => $benchmarkResult->output,
                'error' => $benchmarkResult->error,
                'score' => $useNeutral ? $judgement?->score : $benchmarkResult->score,
                'passed' => $useNeutral ? $judgement?->passed : $benchmarkResult->passed,
                'rationale' => is_string($detail['rationale'] ?? null) ? $detail['rationale'] : null,
                'judge_label' => is_array($judge) ? self::candidateLabel($judge) : null,
            ];
        })->all();
    }

    /**
     * @param  list<int>  $runIds
     * @return Collection<int, BenchmarkRun>
     */
    private function columns(array $runIds, ?int $baselineId): Collection
    {
        $runs = BenchmarkRun::query()->with('schedule:id,name')->whereKey($runIds)->orderBy('id')->get();
        $baseline = $runs->firstWhere('id', $baselineId) ?? $runs->first();

        return $runs->sortBy(static fn (BenchmarkRun $benchmarkRun): int => $benchmarkRun->is($baseline) ? 0 : 1)->values()->toBase();
    }

    /**
     * @param  Collection<int, BenchmarkRun>  $columns
     * @param  list<array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}}>  $pairs
     * @return array{0: list<array{key: string, label: string, paired: bool, members: array<int, int>, member_list: list<array{run_id: int, candidate_index: int}>}>, 1: list<string>}
     */
    private function lines(Collection $columns, array $pairs): array
    {
        $lines = [];

        foreach ($columns as $column) {
            foreach ($column->candidates as $index => $candidate) {
                $autoKey = BenchmarkNeutralJudgement::keyFor($candidate);
                $target = array_find_key($lines, fn ($line): bool => $line['auto_key'] === $autoKey && ! isset($line['members'][$column->id]));

                if ($target === null) {
                    $lines[] = ['auto_key' => $autoKey, 'members' => [$column->id => (int) $index], 'paired' => false];
                } else {
                    $lines[$target]['members'][$column->id] = (int) $index;
                }
            }
        }

        $applied = [];

        foreach ($pairs as [$left, $right]) {
            $leftLine = $this->lineOf($lines, $left);
            $rightLine = $this->lineOf($lines, $right);

            if ($leftLine === null || $rightLine === null || $leftLine === $rightLine) {
                continue;
            }

            if (array_intersect_key($lines[$leftLine]['members'], $lines[$rightLine]['members']) !== []) {
                continue;
            }

            $lines[$leftLine]['members'] += $lines[$rightLine]['members'];
            $lines[$leftLine]['paired'] = true;
            unset($lines[$rightLine]);
            $lines = array_values($lines);
            $applied[] = sprintf('%d.%d~%d.%d', $left[0], $left[1], $right[0], $right[1]);
        }

        return [array_map(fn (array $line): array => $this->describeLine($line, $columns), $lines), $applied];
    }

    /**
     * @param  list<array{auto_key: string, members: array<int, int>, paired: bool}>  $lines
     * @param  array{0: int, 1: int}  $member
     */
    private function lineOf(array $lines, array $member): ?int
    {
        foreach ($lines as $position => $line) {
            if (($line['members'][$member[0]] ?? null) === $member[1]) {
                return $position;
            }
        }

        return null;
    }

    /**
     * @param  array{auto_key: string, members: array<int, int>, paired: bool}  $line
     * @param  Collection<int, BenchmarkRun>  $columns
     * @return array{key: string, label: string, paired: bool, members: array<int, int>, member_list: list<array{run_id: int, candidate_index: int}>}
     */
    private function describeLine(array $line, Collection $columns): array
    {
        $memberList = [];
        $labels = [];

        foreach ($columns as $column) {
            $index = $line['members'][$column->id] ?? null;

            if ($index === null) {
                continue;
            }

            $memberList[] = ['run_id' => $column->id, 'candidate_index' => $index];
            $labels[] = self::candidateLabel((array) ($column->candidates[$index] ?? []));
        }

        return [
            'key' => sprintf('%d.%d', $memberList[0]['run_id'], $memberList[0]['candidate_index']),
            'label' => implode(' / ', array_values(array_unique($labels))),
            'paired' => $line['paired'],
            'members' => $line['members'],
            'member_list' => $memberList,
        ];
    }

    /**
     * @param  array{common: list<int>, excluded: int}  $scope
     * @param  Collection<int, BenchmarkRun>  $columns
     * @param  list<array{key: string, label: string, paired: bool, members: array<int, int>, member_list: list<array{run_id: int, candidate_index: int}>}>  $lines
     * @param  Collection<int, BenchmarkResult>  $taskResults
     * @param  array<string, mixed>|null  $neutralJudge
     * @return array<string, mixed>
     */
    private function task(string $task, array $scope, Collection $columns, array $lines, Collection $taskResults, ?array $neutralJudge): array
    {
        $byMember = $taskResults->groupBy(static fn (BenchmarkResult $benchmarkResult): string => sprintf('%d.%d', $benchmarkResult->benchmark_run_id, $benchmarkResult->candidate_index));
        $byCell = $taskResults->keyBy(static fn (BenchmarkResult $benchmarkResult): string => sprintf('%d.%d.%d', $benchmarkResult->benchmark_run_id, $benchmarkResult->candidate_index, $benchmarkResult->benchmark_case_id));
        $caseNames = $taskResults->mapWithKeys(static fn (BenchmarkResult $benchmarkResult): array => [(int) $benchmarkResult->benchmark_case_id => $benchmarkResult->case_name])->sort();

        $rows = [];
        $cases = [];

        foreach ($lines as $line) {
            $figures = $columns->map(function (BenchmarkRun $benchmarkRun) use ($line, $byMember, $neutralJudge): ?array {
                $index = $line['members'][$benchmarkRun->id] ?? null;
                $memberResults = $index === null ? collect() : $byMember->get(sprintf('%d.%d', $benchmarkRun->id, $index), collect());

                if ($index === null || $memberResults->isEmpty()) {
                    return null;
                }

                $stats = BenchmarkCandidateStats::of($memberResults);
                $finished = $stats['ok'] + $stats['failed'];

                return [
                    ...$stats,
                    'error_rate' => $finished === 0 ? null : round($stats['failed'] / $finished, 4),
                    'self_judged' => $this->sameModel((array) ($benchmarkRun->candidates[$index] ?? []), $neutralJudge ?? $benchmarkRun->judge),
                ];
            })->all();

            if (array_filter($figures) === []) {
                continue;
            }

            $rows[] = ['line_key' => $line['key'], 'figures' => $figures, 'deltas' => $this->deltas($figures)];

            foreach ($caseNames as $caseId => $caseName) {
                $cells = $columns->map(function (BenchmarkRun $benchmarkRun) use ($line, $byCell, $caseId): ?array {
                    $index = $line['members'][$benchmarkRun->id] ?? null;
                    $benchmarkResult = $index === null ? null : $byCell->get(sprintf('%d.%d.%d', $benchmarkRun->id, $index, $caseId));

                    return $benchmarkResult instanceof BenchmarkResult ? [
                        'result_id' => $benchmarkResult->id,
                        'status' => $benchmarkResult->status->value,
                        'score' => $benchmarkResult->score,
                        'passed' => $benchmarkResult->passed,
                        'judge_status' => $benchmarkResult->judge_status->value,
                    ] : null;
                })->all();

                if (array_filter($cells) === []) {
                    continue;
                }

                $cases[] = ['case_id' => $caseId, 'case_name' => $caseName, 'line_key' => $line['key'], 'changed' => $this->changed($cells), 'cells' => $cells];
            }
        }

        return [
            'task' => $task,
            'task_label' => AiTask::tryFrom($task)?->label() ?? $task,
            'common_cases' => count($scope['common']),
            'excluded_cases' => $scope['excluded'],
            'rows' => $rows,
            'cases' => $cases,
        ];
    }

    /**
     * @param  list<array<string, mixed>|null>  $figures
     * @return list<array<string, float|null>|null>
     */
    private function deltas(array $figures): array
    {
        $baseline = $figures[0];

        return array_map(static function (?array $figure, int $position) use ($baseline): ?array {
            if ($position === 0 || $figure === null || $baseline === null) {
                return null;
            }

            $delta = [];

            foreach (self::DELTA_METRICS as $metric) {
                $delta[$metric] = $figure[$metric] === null || $baseline[$metric] === null
                    ? null
                    : round((float) $figure[$metric] - (float) $baseline[$metric], 6);
            }

            return $delta;
        }, $figures, array_keys($figures));
    }

    /**
     * @param  list<array{result_id: int, status: string, score: float|null, passed: bool|null, judge_status: string}|null>  $cells
     */
    private function changed(array $cells): bool
    {
        $baseline = $cells[0];

        if ($baseline === null) {
            return true;
        }

        foreach (array_slice($cells, 1) as $cell) {
            if ($cell === null) {
                continue;
            }

            if ($cell['status'] !== $baseline['status'] || $cell['passed'] !== $baseline['passed']) {
                return true;
            }

            if (($cell['score'] === null) !== ($baseline['score'] === null)) {
                return true;
            }

            if ($cell['score'] !== null && abs($cell['score'] - (float) $baseline['score']) >= self::SCORE_CHANGE - self::TOLERANCE) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $caseIds
     * @return Collection<int, int> keyed by the judged case ids
     */
    private function judgedCaseIds(array $caseIds): Collection
    {
        return BenchmarkCase::query()
            ->whereKey($caseIds)
            ->get(['id', 'task', 'scoring'])
            ->filter(static fn (BenchmarkCase $benchmarkCase): bool => $benchmarkCase->isJudged())
            ->pluck('id')
            ->flip()
            ->toBase();
    }

    /**
     * @param  Collection<int, int>  $judgedCaseIds
     */
    private function isJudgedOk(BenchmarkResult $benchmarkResult, Collection $judgedCaseIds): bool
    {
        return $benchmarkResult->status === BenchmarkResultStatus::Ok && $judgedCaseIds->has((int) $benchmarkResult->benchmark_case_id);
    }

    /**
     * A judged result scored by the neutral judge: its score and verdict, or
     * none while the neutral judge has not scored it. A copy, never saved.
     *
     * @param  Collection<int, BenchmarkNeutralJudgement>  $neutral
     * @param  Collection<int, int>  $judgedCaseIds
     */
    private function withNeutral(BenchmarkResult $benchmarkResult, Collection $neutral, Collection $judgedCaseIds): BenchmarkResult
    {
        if (! $this->isJudgedOk($benchmarkResult, $judgedCaseIds)) {
            return $benchmarkResult;
        }

        $judgement = $neutral->get($benchmarkResult->id);
        $copy = clone $benchmarkResult;
        $copy->score = $judgement?->score;
        $copy->passed = $judgement?->passed;

        return $copy;
    }

    /**
     * Every judge with at least one score on these results, most coverage first.
     *
     * @param  Collection<int, BenchmarkNeutralJudgement>  $judgements
     * @return list<array{key: string, label: string, coverage: float}>
     */
    private function scoreOptions(Collection $judgements, int $judgedTotal): array
    {
        return $judgements
            ->groupBy('judge_key')
            ->map(static fn (Collection $group, string $key): array => [
                'key' => $key,
                'label' => self::candidateLabel((array) $group->first()->judge),
                'coverage' => $judgedTotal === 0 ? 0.0 : round($group->count() / $judgedTotal, 4),
            ])
            ->sortByDesc('coverage')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>|null  $judge
     */
    private function sameModel(array $candidate, ?array $judge): bool
    {
        return is_array($judge)
            && ($judge['provider'] ?? null) === ($candidate['provider'] ?? null)
            && ($judge['model'] ?? null) === ($candidate['model'] ?? null);
    }

    /**
     * @param  list<string>  $tasks
     * @return list<string>
     */
    private function taskOrder(array $tasks): array
    {
        usort($tasks, static function (string $a, string $b): int {
            $position = static fn (string $task): int => ($index = array_search(AiTask::tryFrom($task), AiTask::cases(), true)) === false ? PHP_INT_MAX : $index;

            return $position($a) <=> $position($b);
        });

        return $tasks;
    }
}
