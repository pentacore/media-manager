<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BenchmarkResultStatus;
use App\Enums\BenchmarkRunStatus;
use Carbon\CarbonImmutable;
use Database\Factories\BenchmarkRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * One benchmark execution: a set of cases run against a set of candidate models.
 *
 * @property int $id
 * @property string $name
 * @property BenchmarkRunStatus $status
 * @property list<array<string, mixed>> $candidates
 * @property array<string, mixed>|null $judge
 * @property list<int> $case_ids
 * @property string $estimate_usd
 * @property string|null $cost_cap_usd
 * @property string $cost_usd
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int|null $created_by
 * @property int|null $benchmark_schedule_id
 * @property int|null $baseline_run_id
 * @property list<array{candidate: array{provider: string, model: string, reasoning: string|null}, paired_cases: int, metrics: list<array{metric: string, before: float, after: float}>}>|null $regressions
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, BenchmarkResult> $results
 * @property-read User|null $creator
 * @property-read BenchmarkSchedule|null $schedule
 * @property-read BenchmarkRun|null $baseline
 *
 * @method static BenchmarkRunFactory factory($count = null, $state = [])
 * @method static Builder<static>|BenchmarkRun newModelQuery()
 * @method static Builder<static>|BenchmarkRun newQuery()
 * @method static Builder<static>|BenchmarkRun query()
 * @method static Builder<static>|BenchmarkRun comparable()
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'name',
    'status',
    'candidates',
    'judge',
    'case_ids',
    'estimate_usd',
    'cost_cap_usd',
    'cost_usd',
    'started_at',
    'finished_at',
    'created_by',
    'benchmark_schedule_id',
    'baseline_run_id',
    'regressions',
])]
class BenchmarkRun extends Model
{
    /** @use HasFactory<BenchmarkRunFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'status' => BenchmarkRunStatus::class,
            'candidates' => 'array',
            'judge' => 'array',
            'case_ids' => 'array',
            'estimate_usd' => 'decimal:6',
            'cost_cap_usd' => 'decimal:6',
            'cost_usd' => 'decimal:6',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'regressions' => 'array',
        ];
    }

    /**
     * Whether the run can be compared: it finished (completed or stopped at
     * its cap), or it was cancelled after at least one case finished.
     */
    public function isComparable(): bool
    {
        return match ($this->status) {
            BenchmarkRunStatus::Completed, BenchmarkRunStatus::StoppedCostCap => true,
            BenchmarkRunStatus::Cancelled => $this->results()->whereIn('status', [BenchmarkResultStatus::Ok, BenchmarkResultStatus::Failed])->exists(),
            default => false,
        };
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function scopeComparable(Builder $query): Builder
    {
        return $query->where(fn (Builder $builder): Builder => $builder
            ->whereIn('status', [BenchmarkRunStatus::Completed, BenchmarkRunStatus::StoppedCostCap])
            ->orWhere(fn (Builder $cancelled): Builder => $cancelled
                ->where('status', BenchmarkRunStatus::Cancelled)
                ->whereHas('results', fn (Builder $results): Builder => $results->whereIn('status', [BenchmarkResultStatus::Ok, BenchmarkResultStatus::Failed]))));
    }

    /**
     * @return HasMany<BenchmarkResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(BenchmarkResult::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<BenchmarkSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(BenchmarkSchedule::class, 'benchmark_schedule_id');
    }

    /**
     * @return BelongsTo<BenchmarkRun, $this>
     */
    public function baseline(): BelongsTo
    {
        return $this->belongsTo(self::class, 'baseline_run_id');
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
