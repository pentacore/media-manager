<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiReasoningLevel;
use App\Enums\AiTask;
use App\Observers\AiTaskModelObserver;
use Carbon\CarbonImmutable;
use Database\Factories\AiTaskModelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * One model/reasoning selection for an AI task. `scope` is `default` or, for
 * the decision task, a webhook event key (`service:EventType`). Every null
 * field inherits (see TaskModelResolver).
 *
 * Rows of one task scope form an ordered tier list (`position` 0 first); a tier with pool minimums runs only while its model's free pool has that much left (see TaskModelResolver).
 *
 * @property int $id
 * @property AiTask $task
 * @property string $scope
 * @property int $position
 * @property string|null $provider
 * @property string|null $model
 * @property AiReasoningLevel|null $reasoning
 * @property int|null $min_pool_percent
 * @property int|null $min_pool_tokens
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * @method static Builder<static>|AiTaskModel newModelQuery()
 * @method static Builder<static>|AiTaskModel newQuery()
 * @method static Builder<static>|AiTaskModel query()
 * @method static Builder<static>|AiTaskModel forTask(AiTask $aiTask)
 *
 * @mixin \Eloquent
 */
#[Fillable(['task', 'scope', 'position', 'provider', 'model', 'reasoning', 'min_pool_percent', 'min_pool_tokens'])]
#[ObservedBy(AiTaskModelObserver::class)]
class AiTaskModel extends Model
{
    /** @use HasFactory<AiTaskModelFactory> */
    use HasFactory;

    public const string DEFAULT_SCOPE = 'default';

    /**
     * Whether this tier only runs while its model's free pool has enough left.
     */
    public function hasConditions(): bool
    {
        return $this->min_pool_percent !== null || $this->min_pool_tokens !== null;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function scopeForTask(Builder $query, AiTask $aiTask): Builder
    {
        return $query->where('task', $aiTask->value);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'task' => AiTask::class,
            'position' => 'integer',
            'reasoning' => AiReasoningLevel::class,
            'min_pool_percent' => 'integer',
            'min_pool_tokens' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
