<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BenchmarkJudgeStatus;
use Carbon\CarbonImmutable;
use Database\Factories\BenchmarkNeutralJudgementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * A neutral judge's score for one stored benchmark result. At most one per
 * result and judge key; the result's own score is never touched.
 *
 * @property int $id
 * @property int $benchmark_result_id
 * @property int|null $benchmark_neutral_rejudge_id
 * @property string $judge_key
 * @property array<string, mixed> $judge
 * @property BenchmarkJudgeStatus $status
 * @property float|null $score
 * @property bool|null $passed
 * @property array<string, mixed>|null $detail
 * @property string $cost_usd
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read BenchmarkResult $result
 * @property-read BenchmarkNeutralRejudge|null $rejudge
 *
 * @method static BenchmarkNeutralJudgementFactory factory($count = null, $state = [])
 * @method static Builder<static>|BenchmarkNeutralJudgement newModelQuery()
 * @method static Builder<static>|BenchmarkNeutralJudgement newQuery()
 * @method static Builder<static>|BenchmarkNeutralJudgement query()
 * @method static Builder<static>|BenchmarkNeutralJudgement stale()
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'benchmark_result_id',
    'benchmark_neutral_rejudge_id',
    'judge_key',
    'judge',
    'status',
    'score',
    'passed',
    'detail',
    'cost_usd',
])]
class BenchmarkNeutralJudgement extends Model
{
    /** @use HasFactory<BenchmarkNeutralJudgementFactory> */
    use HasFactory;

    /**
     * The opaque key a judge selection is stored and looked up under.
     *
     * @param  array<string, mixed>  $selection
     */
    public static function keyFor(array $selection): string
    {
        return sprintf(
            '%s|%s|%s',
            is_string($selection['provider'] ?? null) ? $selection['provider'] : '',
            is_string($selection['model'] ?? null) ? $selection['model'] : '',
            is_string($selection['reasoning'] ?? null) && $selection['reasoning'] !== '' ? $selection['reasoning'] : 'provider_default',
        );
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'judge' => 'array',
            'status' => BenchmarkJudgeStatus::class,
            'score' => 'float',
            'passed' => 'boolean',
            'detail' => 'array',
            'cost_usd' => 'decimal:6',
        ];
    }

    /**
     * Judgements queued for or claimed by a job untouched for the claim
     * window: the dispatch was lost or the worker died.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function scopeStale(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [BenchmarkJudgeStatus::Queued, BenchmarkJudgeStatus::Pending])
            ->where('updated_at', '<=', now()->subSeconds(BenchmarkResult::CLAIM_STALE_AFTER_SECONDS));
    }

    /**
     * @return BelongsTo<BenchmarkResult, $this>
     */
    public function result(): BelongsTo
    {
        return $this->belongsTo(BenchmarkResult::class, 'benchmark_result_id');
    }

    /**
     * @return BelongsTo<BenchmarkNeutralRejudge, $this>
     */
    public function rejudge(): BelongsTo
    {
        return $this->belongsTo(BenchmarkNeutralRejudge::class, 'benchmark_neutral_rejudge_id');
    }
}
