<?php

declare(strict_types=1);

namespace App\Models;

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
 * @property string $judge_status
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
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'status' => BenchmarkResultStatus::class,
            'intended_actions' => 'array',
            'score_detail' => 'array',
            'score' => 'float',
            'passed' => 'boolean',
            'cost_usd' => 'decimal:6',
            'judge_cost_usd' => 'decimal:6',
        ];
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
