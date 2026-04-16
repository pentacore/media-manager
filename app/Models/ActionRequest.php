<?php

declare(strict_types=1);

namespace App\Models;

use Override;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $approvedByUser
 * @property-read WebhookEvent|null $webhookEvent
 * @method static ActionRequestFactory factory($count = null, $state = [])
 * @method static Builder<static>|ActionRequest newModelQuery()
 * @method static Builder<static>|ActionRequest newQuery()
 * @method static Builder<static>|ActionRequest query()
 * @method static Builder<static>|ActionRequest whereApprovedBy($value)
 * @method static Builder<static>|ActionRequest whereCreatedAt($value)
 * @method static Builder<static>|ActionRequest whereId($value)
 * @method static Builder<static>|ActionRequest wherePayload($value)
 * @method static Builder<static>|ActionRequest whereRequiresApproval($value)
 * @method static Builder<static>|ActionRequest whereResult($value)
 * @method static Builder<static>|ActionRequest whereSourceService($value)
 * @method static Builder<static>|ActionRequest whereStatus($value)
 * @method static Builder<static>|ActionRequest whereTargetService($value)
 * @method static Builder<static>|ActionRequest whereType($value)
 * @method static Builder<static>|ActionRequest whereUpdatedAt($value)
 * @method static Builder<static>|ActionRequest whereWebhookEventId($value)
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
    #[Override]
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
