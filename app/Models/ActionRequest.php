<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActionRequestStatus;
use Database\Factories\ActionRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
