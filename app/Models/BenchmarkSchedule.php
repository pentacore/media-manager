<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiTask;
use App\Enums\BenchmarkScheduleFrequency;
use App\Enums\BenchmarkScheduleOutcome;
use Carbon\CarbonImmutable;
use Database\Factories\BenchmarkScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Override;

/**
 * A recurring benchmark: runs a task's cases against its tier models on a cadence.
 *
 * @property int $id
 * @property string $name
 * @property AiTask $task
 * @property list<string> $case_tags
 * @property array{provider: string, model: string, reasoning: string|null} $judge
 * @property string $cost_cap_usd
 * @property BenchmarkScheduleFrequency $frequency
 * @property string $time_of_day
 * @property int|null $weekday
 * @property int|null $day_of_month
 * @property string $pass_rate_threshold
 * @property string $score_threshold
 * @property bool $enabled
 * @property CarbonImmutable|null $next_run_at
 * @property CarbonImmutable|null $last_run_at
 * @property BenchmarkScheduleOutcome|null $last_outcome
 * @property string|null $last_message
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $creator
 * @property-read Collection<int, BenchmarkRun> $runs
 * @property-read BenchmarkRun|null $latestRun
 *
 * @method static BenchmarkScheduleFactory factory($count = null, $state = [])
 * @method static Builder<static>|BenchmarkSchedule newModelQuery()
 * @method static Builder<static>|BenchmarkSchedule newQuery()
 * @method static Builder<static>|BenchmarkSchedule query()
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'name',
    'task',
    'case_tags',
    'judge',
    'cost_cap_usd',
    'frequency',
    'time_of_day',
    'weekday',
    'day_of_month',
    'pass_rate_threshold',
    'score_threshold',
    'enabled',
    'next_run_at',
    'last_run_at',
    'last_outcome',
    'last_message',
    'created_by',
])]
class BenchmarkSchedule extends Model
{
    /** @use HasFactory<BenchmarkScheduleFactory> */
    use HasFactory;

    private const array WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'task' => AiTask::class,
            'case_tags' => 'array',
            'judge' => 'array',
            'cost_cap_usd' => 'decimal:6',
            'frequency' => BenchmarkScheduleFrequency::class,
            'weekday' => 'integer',
            'day_of_month' => 'integer',
            'pass_rate_threshold' => 'decimal:2',
            'score_threshold' => 'decimal:2',
            'enabled' => 'boolean',
            'next_run_at' => 'immutable_datetime',
            'last_run_at' => 'immutable_datetime',
            'last_outcome' => BenchmarkScheduleOutcome::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<BenchmarkRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(BenchmarkRun::class);
    }

    /**
     * @return HasOne<BenchmarkRun, $this>
     */
    public function latestRun(): HasOne
    {
        return $this->hasOne(BenchmarkRun::class)->latestOfMany();
    }

    public function cadenceLabel(): string
    {
        return match ($this->frequency) {
            BenchmarkScheduleFrequency::Daily => sprintf('Daily at %s', $this->time_of_day),
            BenchmarkScheduleFrequency::Weekly => sprintf('Weekly on %s at %s', self::WEEKDAYS[$this->weekday ?? 1], $this->time_of_day),
            BenchmarkScheduleFrequency::Monthly => sprintf('Monthly on day %d at %s', $this->day_of_month ?? 1, $this->time_of_day),
        };
    }

    /**
     * @return array<int, string>
     */
    public static function weekdays(): array
    {
        return self::WEEKDAYS;
    }
}
