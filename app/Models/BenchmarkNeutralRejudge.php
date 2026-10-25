<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BenchmarkNeutralRejudgeStatus;
use Carbon\CarbonImmutable;
use Database\Factories\BenchmarkNeutralRejudgeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * One neutral re-judge: a judge an admin picked re-scores the stored outputs
 * of the runs being compared, within a cost cap. Its judgements belong to the
 * results, so later comparisons reuse them.
 *
 * @property int $id
 * @property list<int> $run_ids
 * @property array<string, mixed> $judge
 * @property string $judge_key
 * @property BenchmarkNeutralRejudgeStatus $status
 * @property string $estimate_usd
 * @property string $cost_cap_usd
 * @property string $cost_usd
 * @property int|null $created_by
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, BenchmarkNeutralJudgement> $judgements
 * @property-read User|null $creator
 *
 * @method static BenchmarkNeutralRejudgeFactory factory($count = null, $state = [])
 * @method static Builder<static>|BenchmarkNeutralRejudge newModelQuery()
 * @method static Builder<static>|BenchmarkNeutralRejudge newQuery()
 * @method static Builder<static>|BenchmarkNeutralRejudge query()
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'run_ids',
    'judge',
    'judge_key',
    'status',
    'estimate_usd',
    'cost_cap_usd',
    'cost_usd',
    'created_by',
    'started_at',
    'finished_at',
])]
class BenchmarkNeutralRejudge extends Model
{
    /** @use HasFactory<BenchmarkNeutralRejudgeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'run_ids' => 'array',
            'judge' => 'array',
            'status' => BenchmarkNeutralRejudgeStatus::class,
            'estimate_usd' => 'decimal:6',
            'cost_cap_usd' => 'decimal:6',
            'cost_usd' => 'decimal:6',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<BenchmarkNeutralJudgement, $this>
     */
    public function judgements(): HasMany
    {
        return $this->hasMany(BenchmarkNeutralJudgement::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
