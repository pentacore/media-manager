<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentDecisionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\AgentDecisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int|null $webhook_event_id
 * @property string|null $service
 * @property string|null $event_type
 * @property AgentDecisionStatus $status
 * @property string|null $summary
 * @property int $actions_count
 * @property array<array-key, mixed>|null $action_request_ids
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read WebhookEvent|null $webhookEvent
 *
 * @method static AgentDecisionFactory factory($count = null, $state = [])
 * @method static Builder<static>|AgentDecision newModelQuery()
 * @method static Builder<static>|AgentDecision newQuery()
 * @method static Builder<static>|AgentDecision query()
 *
 * @mixin \Eloquent
 */
#[Fillable(['webhook_event_id', 'service', 'event_type', 'status', 'summary', 'actions_count', 'action_request_ids'])]
class AgentDecision extends Model
{
    /** @use HasFactory<AgentDecisionFactory> */
    use HasFactory;

    use MassPrunable;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'status' => AgentDecisionStatus::class,
            'actions_count' => 'integer',
            'action_request_ids' => 'array',
        ];
    }

    /**
     * @return BelongsTo<WebhookEvent, $this>
     */
    public function webhookEvent(): BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class);
    }

    /**
     * Retention window from mediamanager.retention (0 disables pruning).
     */
    public function prunable(): Builder
    {
        $days = (int) config('mediamanager.retention.agent_decisions_days');

        return static::query()->when(
            $days > 0,
            fn (Builder $builder): Builder => $builder->where('created_at', '<', now()->subDays($days)),
            fn (Builder $builder): Builder => $builder->whereRaw('1 = 0'),
        );
    }
}
