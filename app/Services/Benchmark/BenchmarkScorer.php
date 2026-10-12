<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Ai\Agents\BenchmarkJudgeAgent;
use App\Ai\Benchmark\BenchmarkRunContext;
use App\Ai\Benchmark\FixtureBook;
use App\Ai\Benchmark\ToolArguments;
use App\Ai\ResolvedSelection;
use App\Ai\Tools\PriceFetcher\UpsertModelPriceTool;
use App\Enums\AiMode;
use App\Enums\AiTask;
use App\Enums\BenchmarkJudgeStatus;
use App\Enums\BenchmarkScoringType;
use App\Models\BenchmarkCase;
use App\Services\AiBudget\AiBudgetExceededException;
use App\Services\AiBudget\AiBudgetGuard;
use App\Services\AiUsage\ModelPriceLookup;
use App\Services\AiUsage\Pricing\RefreshScope;
use App\Services\AiUsage\UsageColumns;
use App\Support\UpstreamErrorText;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Throwable;

/**
 * Scores one case outcome by the case's scoring rule: deterministic checks
 * for decision actions and price-updater writes, the blind LLM judge for
 * every other task. Details never use the `tool_log` key: the run job merges
 * the tool log into the stored detail itself.
 */
final readonly class BenchmarkScorer
{
    private const int DEFAULT_PASS_THRESHOLD = 7;

    private const int ERROR_LIMIT = 2000;

    private const float DEFAULT_TOLERANCE_PERCENT = 1.0;

    /** The price fields a case may expect, in the order mismatches are listed. */
    private const array PRICE_FIELDS = ['input_per_mtok', 'output_per_mtok', 'cache_read_per_mtok', 'cache_write_per_mtok'];

    public function __construct(
        private ModelPriceLookup $modelPriceLookup,
        private BenchmarkRunContext $benchmarkRunContext,
        private AiBudgetGuard $aiBudgetGuard,
    ) {}

    /**
     * @return array{score: float|null, passed: bool|null, detail: array<string, mixed>, judge_status: BenchmarkJudgeStatus, judge_cost_usd: float}
     */
    public function score(BenchmarkCase $benchmarkCase, CaseOutcome $caseOutcome): array
    {
        $scoring = $benchmarkCase->scoring;
        $scoringType = BenchmarkScoringType::tryFrom((string) ($scoring['type'] ?? '')) ?? $benchmarkCase->task->benchmarkScoringType();

        return match ($scoringType) {
            BenchmarkScoringType::Actions => $this->scoreActions($scoring, $caseOutcome),
            BenchmarkScoringType::Prices => $this->scorePrices($scoring, $caseOutcome),
            BenchmarkScoringType::Judge => $this->judge($benchmarkCase, $caseOutcome),
        };
    }

    /**
     * Greedy match: each expected action takes the first unused intended
     * action with the same tool whose arguments contain the expected subset.
     * Score is the F1 of precision and recall; pass needs no missing and no
     * extra action.
     *
     * @param  array<string, mixed>  $scoring
     * @return array{score: float, passed: bool, detail: array<string, mixed>, judge_status: BenchmarkJudgeStatus, judge_cost_usd: float}
     */
    private function scoreActions(array $scoring, CaseOutcome $caseOutcome): array
    {
        /** @var list<array{tool: string, arguments?: array<string, mixed>}> $expected */
        $expected = array_values((array) ($scoring['expected'] ?? []));
        $intended = $caseOutcome->intendedActions;

        $used = [];
        $matched = [];
        $missing = [];

        foreach ($expected as $expectedAction) {
            $matchIndex = null;

            foreach ($intended as $index => $intendedAction) {
                if (isset($used[$index]) || $intendedAction['tool'] !== $expectedAction['tool']) {
                    continue;
                }

                if (ToolArguments::contains($intendedAction['arguments'], (array) ($expectedAction['arguments'] ?? []))) {
                    $matchIndex = $index;

                    break;
                }
            }

            if ($matchIndex === null) {
                $missing[] = $expectedAction;

                continue;
            }

            $used[$matchIndex] = true;
            $matched[] = $intended[$matchIndex];
        }

        $extra = array_values(array_filter($intended, static fn (int $index): bool => ! isset($used[$index]), ARRAY_FILTER_USE_KEY));

        $bothEmpty = $expected === [] && $intended === [];
        $precision = $bothEmpty ? 1.0 : count($matched) / max(count($intended), 1);
        $recall = $bothEmpty ? 1.0 : count($matched) / max(count($expected), 1);
        $score = $precision + $recall > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;

        return [
            'score' => round($score, 4),
            'passed' => $missing === [] && $extra === [],
            'detail' => [
                'matched' => $matched,
                'missing' => $missing,
                'extra' => $extra,
                'precision' => round($precision, 4),
                'recall' => round($recall, 4),
            ],
            'judge_status' => BenchmarkJudgeStatus::None,
            'judge_cost_usd' => 0.0,
        ];
    }

    /**
     * Compare each expected rate with the agent's UpsertModelPriceTool call
     * for the same provider and model (the last call wins), relative to the
     * case tolerance; an expected zero needs an exact zero.
     *
     * @param  array<string, mixed>  $scoring
     * @return array{score: float, passed: bool, detail: array<string, mixed>, judge_status: BenchmarkJudgeStatus, judge_cost_usd: float}
     */
    private function scorePrices(array $scoring, CaseOutcome $caseOutcome): array
    {
        $tolerance = (float) ($scoring['tolerance_percent'] ?? self::DEFAULT_TOLERANCE_PERCENT);
        $upsertTool = class_basename(UpsertModelPriceTool::class);
        $written = [];

        foreach ($caseOutcome->intendedActions as $intendedAction) {
            if ($intendedAction['tool'] !== $upsertTool) {
                continue;
            }

            $arguments = $intendedAction['arguments'];
            $written[$this->priceKey($arguments['provider'] ?? null, $arguments['model'] ?? null)] = $arguments;
        }

        $fields = 0;
        $matchedFields = 0;
        $mismatches = [];

        foreach ((array) ($scoring['expected'] ?? []) as $expectedRow) {
            $actualRow = $written[$this->priceKey($expectedRow['provider'] ?? null, $expectedRow['model'] ?? null)] ?? null;

            foreach (self::PRICE_FIELDS as $field) {
                if (! isset($expectedRow[$field]) || ! is_numeric($expectedRow[$field])) {
                    continue;
                }

                $fields++;
                $expectedRate = (float) $expectedRow[$field];
                $actualRate = isset($actualRow[$field]) && is_numeric($actualRow[$field]) ? (float) $actualRow[$field] : null;

                if ($actualRate !== null && $this->withinTolerance($expectedRate, $actualRate, $tolerance)) {
                    $matchedFields++;

                    continue;
                }

                $mismatches[] = [
                    'provider' => $expectedRow['provider'] ?? null,
                    'model' => $expectedRow['model'] ?? null,
                    'field' => $field,
                    'expected' => $expectedRate,
                    'actual' => $actualRate,
                ];
            }
        }

        return [
            'score' => $fields === 0 ? 1.0 : round($matchedFields / $fields, 4),
            'passed' => $mismatches === [],
            'detail' => ['mismatches' => $mismatches],
            'judge_status' => BenchmarkJudgeStatus::None,
            'judge_cost_usd' => 0.0,
        ];
    }

    /**
     * Ask the blind judge to grade the output against the task rubric, after
     * the monthly hard cap allows it (the candidate's usage row is already in
     * the month's spend). A
     * refusal or judge failure leaves the result unscored (re-judgeable);
     * a judge failure is reported.
     *
     * @return array{score: float|null, passed: bool|null, detail: array<string, mixed>, judge_status: BenchmarkJudgeStatus, judge_cost_usd: float}
     */
    private function judge(BenchmarkCase $benchmarkCase, CaseOutcome $caseOutcome): array
    {
        $scoring = $benchmarkCase->scoring;
        $threshold = (int) ($scoring['pass_threshold'] ?? self::DEFAULT_PASS_THRESHOLD);

        $judgePrompt = DefaultRubrics::judgePrompt(
            $benchmarkCase->task,
            (string) ($benchmarkCase->input['prompt'] ?? ''),
            $this->history($benchmarkCase),
            $this->toolResults($caseOutcome),
            $caseOutcome->output,
            isset($scoring['rubric_note']) && is_string($scoring['rubric_note']) ? $scoring['rubric_note'] : null,
            $benchmarkCase->task === AiTask::Chat ? AiMode::tryFrom((string) ($benchmarkCase->input['mode'] ?? '')) : null,
        );

        try {
            $this->aiBudgetGuard->enforce();

            $agentResponse = resolve(BenchmarkJudgeAgent::class)->prompt($judgePrompt);

            $rawScore = $agentResponse instanceof StructuredAgentResponse ? ($agentResponse['score'] ?? null) : null;
            $rationale = $agentResponse instanceof StructuredAgentResponse ? ($agentResponse['rationale'] ?? '') : '';

            throw_unless(is_numeric($rawScore), RuntimeException::class, 'The judge returned no score.');

            $score10 = max(1, min(10, (int) round((float) $rawScore)));
        } catch (Throwable $throwable) {
            report_unless($throwable instanceof AiBudgetExceededException, $throwable);

            return [
                'score' => null,
                'passed' => null,
                'detail' => ['error' => UpstreamErrorText::sanitize($throwable->getMessage(), self::ERROR_LIMIT)],
                'judge_status' => BenchmarkJudgeStatus::Failed,
                'judge_cost_usd' => 0.0,
            ];
        }

        return [
            'score' => round($score10 / 10, 4),
            'passed' => $score10 >= $threshold,
            'detail' => ['score_10' => $score10, 'rationale' => is_string($rationale) ? $rationale : ''],
            'judge_status' => BenchmarkJudgeStatus::Ok,
            'judge_cost_usd' => $this->judgeCost($agentResponse),
        ];
    }

    /**
     * The judge's USD cost at the catalog price of the model that answered
     * (the run's judge selection when the response names none); 0 when unpriced.
     */
    private function judgeCost(AgentResponse $agentResponse): float
    {
        $judgeSelection = $this->benchmarkRunContext->selectionFor(AiTask::BenchmarkJudge);
        $provider = $agentResponse->meta->provider ?? ($judgeSelection instanceof ResolvedSelection ? $judgeSelection->provider : null);
        $model = $agentResponse->meta->model ?? ($judgeSelection instanceof ResolvedSelection ? $judgeSelection->model : null);

        $snapshot = $this->modelPriceLookup->snapshotFor($provider, $model, false);

        return $snapshot === null ? 0.0 : $this->modelPriceLookup->costOf(UsageColumns::fromText($agentResponse->usage), $snapshot);
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function history(BenchmarkCase $benchmarkCase): array
    {
        $history = [];

        foreach ((array) ($benchmarkCase->input['history'] ?? []) as $turn) {
            if (! is_array($turn) || ! is_string($turn['role'] ?? null) || ! is_string($turn['content'] ?? null)) {
                continue;
            }

            $history[] = ['role' => $turn['role'], 'content' => $turn['content']];
        }

        return $history;
    }

    /**
     * Every tool call with the answer the candidate saw (a miss saw the
     * not-recorded result).
     *
     * @return list<array{tool: string, arguments: array<string, mixed>, result: string}>
     */
    private function toolResults(CaseOutcome $caseOutcome): array
    {
        return array_map(
            static fn (array $call): array => [
                'tool' => $call['tool'],
                'arguments' => $call['arguments'],
                'result' => $call['result'] ?? FixtureBook::MISS_RESULT,
            ],
            $caseOutcome->toolLog,
        );
    }

    private function priceKey(mixed $provider, mixed $model): string
    {
        $provider = mb_strtolower(trim(is_string($provider) ? $provider : ''));
        $model = mb_strtolower(trim(is_string($model) ? $model : ''));

        return sprintf('%s|%s', RefreshScope::canonicalProvider($provider) ?? $provider, $model);
    }

    private function withinTolerance(float $expected, float $actual, float $tolerancePercent): bool
    {
        if ($expected === 0.0) {
            return $actual === 0.0;
        }

        return abs($actual - $expected) <= abs($expected) * $tolerancePercent / 100 + 1e-12;
    }
}
