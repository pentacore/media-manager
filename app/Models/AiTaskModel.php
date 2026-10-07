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
 * @property int $id
 * @property AiTask $task
 * @property string $scope
 * @property string|null $provider
 * @property string|null $model
 * @property AiReasoningLevel|null $reasoning
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
#[Fillable(['task', 'scope', 'provider', 'model', 'reasoning'])]
#[ObservedBy(AiTaskModelObserver::class)]
class AiTaskModel extends Model
{
    /** @use HasFactory<AiTaskModelFactory> */
    use HasFactory;

    public const string DEFAULT_SCOPE = 'default';

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
            'reasoning' => AiReasoningLevel::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
