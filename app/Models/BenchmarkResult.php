<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BenchmarkJudgeStatus;
use App\Enums\BenchmarkResultStatus;
use Carbon\CarbonImmutable;
use Database\Factories\BenchmarkResultFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * One candidate's outcome on one case. Case name and task are copied so the
 * row survives deletion of the case.
 *
 * @property int $id
 * @property int $benchmark_run_id
 * @property int|null $benchmark_case_id
 * @property string $case_name
 * @property string $case_task
 * @property int $candidate_index
 * @property BenchmarkResultStatus $status
 * @property string|null $output
 * @property list<array<string, mixed>> $intended_actions
 * @property int $prompt_tokens
 * @property int $completion_tokens
 * @property string $cost_usd
 * @property int|null $latency_ms
 * @property int $tool_calls
 * @property int $tool_misses
 * @property string|null $error
 * @property float|null $score
 * @property bool|null $passed
 * @property array<string, mixed>|null $score_detail
 * @property BenchmarkJudgeStatus $judge_status
 * @property string $judge_cost_usd
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read BenchmarkRun $run
 * @property-read BenchmarkCase|null $benchmarkCase
 *
 * @method static BenchmarkResultFactory factory($count = null, $state = [])
 * @method static Builder<static>|BenchmarkResult newModelQuery()
 * @method static Builder<static>|BenchmarkResult newQuery()
 * @method static Builder<static>|BenchmarkResult query()
 * @method static Builder<static>|BenchmarkResult claimable()
 * @method static Builder<static>|BenchmarkResult inFlight()
 * @method static Builder<static>|BenchmarkResult runStale()
 * @method static Builder<static>|BenchmarkResult judgeStale()
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'benchmark_run_id',
    'benchmark_case_id',
    'case_name',
    'case_task',
    'candidate_index',
    'status',
    'output',
    'intended_actions',
    'prompt_tokens',
    'completion_tokens',
    'cost_usd',
    'latency_ms',
    'tool_calls',
    'tool_misses',
    'error',
    'score',
    'passed',
    'score_detail',
    'judge_status',
    'judge_cost_usd',
])]
class BenchmarkResult extends Model
{
    /** @use HasFactory<BenchmarkResultFactory> */
    use HasFactory;

    /**
     * A job runs for at most 280 s (its timeout), so a result still claimed
     * this long after the claim lost its worker (killed mid-run): it may be
     * claimed again and no longer blocks deleting the run.
     */
    public const int CLAIM_STALE_AFTER_SECONDS = 300;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'status' => BenchmarkResultStatus::class,
            'judge_status' => BenchmarkJudgeStatus::class,
            'intended_actions' => 'array',
            'score_detail' => 'array',
            'score' => 'float',
            'passed' => 'boolean',
            'cost_usd' => 'decimal:6',
            'judge_cost_usd' => 'decimal:6',
        ];
    }

    /**
     * Whether the result is running under a claim whose worker died.
     */
    public function isRunStale(): bool
    {
        return $this->status === BenchmarkResultStatus::Running && $this->claimOutlived();
    }

    /**
     * Whether the result's re-judge stalled: queued with its dispatch lost,
     * or claimed by a judge-only job whose worker died.
     */
    public function isJudgeStale(): bool
    {
        return $this->judge_status->isRejudging() && $this->claimOutlived();
    }

    /**
     * Results a job may claim to run: pending, or running under a stale claim.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function scopeClaimable(Builder $query): Builder
    {
        return $query->where(fn (Builder $builder): Builder => $builder
            ->where('status', BenchmarkResultStatus::Pending)
            ->orWhere(fn (Builder $stale): Builder => $stale->runStale()));
    }

    /**
     * Results running under a claim untouched for the stale window.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function scopeRunStale(Builder $query): Builder
    {
        return $query
            ->where('status', BenchmarkResultStatus::Running)
            ->where('updated_at', '<=', $this->staleBefore());
    }

    /**
     * Results queued for or claimed by a re-judge untouched for the stale
     * window: a judge-only job may take them over and they may be re-judged
     * again.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function scopeJudgeStale(Builder $query): Builder
    {
        return $query
            ->whereIn('judge_status', [BenchmarkJudgeStatus::Queued, BenchmarkJudgeStatus::Pending])
            ->where('updated_at', '<=', $this->staleBefore());
    }

    /**
     * Results a live job is working on now: a case running or a re-judge
     * scoring, claimed within the stale window. Their jobs still write usage
     * rows tagged with the run.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function scopeInFlight(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $builder): Builder => $builder
                ->where('status', BenchmarkResultStatus::Running)
                ->orWhere('judge_status', BenchmarkJudgeStatus::Pending))
            ->where('updated_at', '>', $this->staleBefore());
    }

    private function claimOutlived(): bool
    {
        return ! $this->updated_at instanceof CarbonImmutable || $this->updated_at->lessThanOrEqualTo($this->staleBefore());
    }

    private function staleBefore(): CarbonImmutable
    {
        return now()->subSeconds(self::CLAIM_STALE_AFTER_SECONDS);
    }

    /**
     * @return BelongsTo<BenchmarkRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(BenchmarkRun::class, 'benchmark_run_id');
    }

    /**
     * @return BelongsTo<BenchmarkCase, $this>
     */
    public function benchmarkCase(): BelongsTo
    {
        return $this->belongsTo(BenchmarkCase::class);
    }
}
