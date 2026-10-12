<?php

declare(strict_types=1);

namespace App\Models;

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
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, BenchmarkResult> $results
 * @property-read User|null $creator
 *
 * @method static BenchmarkRunFactory factory($count = null, $state = [])
 * @method static Builder<static>|BenchmarkRun newModelQuery()
 * @method static Builder<static>|BenchmarkRun newQuery()
 * @method static Builder<static>|BenchmarkRun query()
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
        ];
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

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
