<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActionRequestStatus;
use Database\Factories\ActionRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $webhook_event_id
 * @property string $type
 * @property string $source_service
 * @property string $target_service
 * @property ActionRequestStatus $status
 * @property bool $requires_approval
 * @property int|null $approved_by
 * @property array<array-key, mixed> $payload
 * @property array<array-key, mixed>|null $result
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \App\Models\User|null $approvedByUser
 * @property-read \App\Models\WebhookEvent|null $webhookEvent
 * @method static \Database\Factories\ActionRequestFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActionRequest newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActionRequest newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActionRequest query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActionRequest whereApprovedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActionRequest whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActionRequest whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActionRequest wherePayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActionRequest whereRequiresApproval($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActionRequest whereResult($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActionRequest whereSourceService($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActionRequest whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActionRequest whereTargetService($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActionRequest whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActionRequest whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActionRequest whereWebhookEventId($value)
 * @mixin \Eloquent
 */
#[Fillable(['webhook_event_id', 'type', 'source_service', 'target_service', 'status', 'requires_approval', 'approved_by', 'payload', 'result'])]
class ActionRequest extends Model
{
    /** @use HasFactory<ActionRequestFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'status' => ActionRequestStatus::class,
            'requires_approval' => 'boolean',
            'payload' => 'array',
            'result' => 'array',
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
     * @return BelongsTo<User, $this>
     */
    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isPending(): bool
    {
        return $this->status === ActionRequestStatus::Pending;
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
