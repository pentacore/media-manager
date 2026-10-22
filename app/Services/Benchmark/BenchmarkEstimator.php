<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

use App\Models\BenchmarkCase;
use App\Services\AiUsage\ModelPriceLookup;
use Illuminate\Support\Collection;

/**
 * The pre-run cost estimate: per case × candidate, the case's source token
 * counts (a per-case default for hand-written cases) at the candidate's
 * catalog price, plus a judge allowance for every judged result. Unpriced
 * models contribute $0 and are listed so the form can flag them.
 */
final readonly class BenchmarkEstimator
{
    public const int DEFAULT_PROMPT_TOKENS = 2_000;

    public const int DEFAULT_COMPLETION_TOKENS = 500;

    /** The judge prompt beyond the candidate output: case prompt, rubric, tool results. */
    private const int JUDGE_PROMPT_TOKENS = 1_500;

    private const int JUDGE_COMPLETION_TOKENS = 300;

    public function __construct(private ModelPriceLookup $modelPriceLookup) {}

    /**
     * @param  Collection<int, BenchmarkCase>  $cases
     * @param  list<array<string, mixed>>  $candidates  `{provider, model, reasoning}` selections
     * @param  array<string, mixed>|null  $judge
     * @return array{total_usd: float, rows: list<array{case_id: int, candidate_index: int, usd: float, priced: bool}>, judge_usd: float, unpriced: list<string>}
     */
    public function estimate(Collection $cases, array $candidates, ?array $judge): array
    {
        $candidateSnapshots = array_map($this->snapshot(...), $candidates);
        $judgeSnapshot = $judge === null ? null : $this->snapshot($judge);

        $rows = [];
        $judgeUsd = 0.0;
        $judgeUsed = false;

        foreach ($cases as $case) {
            $promptTokens = $case->source_prompt_tokens ?? self::DEFAULT_PROMPT_TOKENS;
            $completionTokens = $case->source_completion_tokens ?? self::DEFAULT_COMPLETION_TOKENS;
            $judged = $case->isJudged();

            foreach ($candidates as $index => $candidate) {
                $snapshot = $candidateSnapshots[$index];

                $rows[] = [
                    'case_id' => $case->id,
                    'candidate_index' => $index,
                    'usd' => $snapshot === null ? 0.0 : $this->modelPriceLookup->costOf([
                        'prompt_tokens' => $promptTokens,
                        'completion_tokens' => $completionTokens,
                    ], $snapshot),
                    'priced' => $snapshot !== null,
                ];

                if (! $judged || $judge === null) {
                    continue;
                }

                $judgeUsed = true;

                if ($judgeSnapshot === null) {
                    continue;
                }

                $judgeUsd += $this->modelPriceLookup->costOf([
                    'prompt_tokens' => self::JUDGE_PROMPT_TOKENS + $completionTokens * 2,
                    'completion_tokens' => self::JUDGE_COMPLETION_TOKENS,
                ], $judgeSnapshot);
            }
        }

        $judgeUsd = round($judgeUsd, 6);

        $unpriced = [];

        foreach ($candidates as $index => $candidate) {
            if ($candidateSnapshots[$index] === null) {
                $unpriced[] = $this->label($candidate);
            }
        }

        if ($judgeUsed && $judge !== null && $judgeSnapshot === null) {
            $unpriced[] = $this->label($judge);
        }

        return [
            'total_usd' => round(array_sum(array_column($rows, 'usd')) + $judgeUsd, 6),
            'rows' => $rows,
            'judge_usd' => $judgeUsd,
            'unpriced' => array_values(array_unique($unpriced)),
        ];
    }

    /**
     * @param  array<string, mixed>  $selection
     * @return array<string, string>|null
     */
    private function snapshot(array $selection): ?array
    {
        $provider = $selection['provider'] ?? null;
        $model = $selection['model'] ?? null;

        return $this->modelPriceLookup->snapshotFor(
            is_string($provider) ? $provider : null,
            is_string($model) ? $model : null,
            false,
        );
    }

    /**
     * @param  array<string, mixed>  $selection
     */
    private function label(array $selection): string
    {
        $provider = $selection['provider'] ?? null;
        $model = $selection['model'] ?? null;

        return sprintf('%s/%s', is_string($provider) ? $provider : '', is_string($model) ? $model : '');
    }
}
