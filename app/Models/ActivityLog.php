<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\ActivityLogObserver;
use Carbon\CarbonImmutable;
use Database\Factories\ActivityLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Override;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $service_connection_id
 * @property int|null $webhook_event_id
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string $description
 * @property array<array-key, mixed>|null $metadata
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ServiceConnection|null $serviceConnection
 * @property-read WebhookEvent|null $webhookEvent
 * @property-read Model|\Eloquent|null $subject
 * @property-read User|null $user
 *
 * @method static ActivityLogFactory factory($count = null, $state = [])
 * @method static Builder<static>|ActivityLog newModelQuery()
 * @method static Builder<static>|ActivityLog newQuery()
 * @method static Builder<static>|ActivityLog query()
 * @method static Builder<static>|ActivityLog whereAction($value)
 * @method static Builder<static>|ActivityLog whereCreatedAt($value)
 * @method static Builder<static>|ActivityLog whereDescription($value)
 * @method static Builder<static>|ActivityLog whereId($value)
 * @method static Builder<static>|ActivityLog whereMetadata($value)
 * @method static Builder<static>|ActivityLog whereServiceConnectionId($value)
 * @method static Builder<static>|ActivityLog whereSubjectId($value)
 * @method static Builder<static>|ActivityLog whereSubjectType($value)
 * @method static Builder<static>|ActivityLog whereUpdatedAt($value)
 * @method static Builder<static>|ActivityLog whereUserId($value)
 *
 * @mixin \Eloquent
 */
#[ObservedBy(ActivityLogObserver::class)]
#[Fillable(['user_id', 'service_connection_id', 'webhook_event_id', 'action', 'subject_type', 'subject_id', 'description', 'metadata'])]
#[TypefinderOverrides(['metadata' => 'Record<string|number, any> | null'])]
class ActivityLog extends Model
{
    /** @use HasFactory<ActivityLogFactory> */
    use HasFactory;
    use MassPrunable;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ServiceConnection, $this>
     */
    public function serviceConnection(): BelongsTo
    {
        return $this->belongsTo(ServiceConnection::class);
    }

    /**
     * @return BelongsTo<WebhookEvent, $this>
     */
    public function webhookEvent(): BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Retention window from mediamanager.retention (0 disables pruning).
     */
    public function prunable(): Builder
    {
        $days = (int) config('mediamanager.retention.activity_logs_days');

        return static::query()->when(
            $days > 0,
            fn (Builder $builder): Builder => $builder->where('created_at', '<', now()->subDays($days)),
            fn (Builder $builder): Builder => $builder->whereRaw('1 = 0'),
        );
    }
}
