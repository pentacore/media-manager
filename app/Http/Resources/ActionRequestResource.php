<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ActionRequest;
use App\Services\Actions\ActionRequestBrowserPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;
use Pentacore\Typefinder\Attributes\TypefinderResource;

/**
 * @mixin ActionRequest
 */
#[TypefinderResource(shape: [
    'id' => 'number',
    'type' => 'string',
    'origin' => "'system' | 'chat' | 'agent' | 'manual'",
    'title' => 'string | null',
    'description' => 'string | null',
    'details' => 'Array<{ label: string; value: string }>',
    'description_verified' => 'boolean',
    'agent_rationale' => 'string | null',
    'second_opinion' => "{ engine: 'llm' | 'jev'; mode: 'hold' | 'reject'; agrees: boolean; failed: boolean; confidence: number | null; reason: string | null; override_event: string | null } | { skipped: true; override_event: string | null } | null",
    'source_service' => 'string',
    'target_service' => 'string',
    'status' => "'pending' | 'approved' | 'executing' | 'completed' | 'failed' | 'rejected'",
    'status_version' => 'number',
    'requires_approval' => 'boolean',
    'payload' => 'Record<string, unknown>',
    'result' => 'Record<string, unknown> | null',
    'approved_by' => 'string | null',
    'webhook_source' => 'string | null',
    'replacement_attempt' => '{ id: number; status: string; failure_reason: string | null } | null',
    'created_at' => 'string | null',
    'updated_at' => 'string | null',
])]
class ActionRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'origin' => $this->origin,
            ...resolve(ActionRequestBrowserPayload::class)->description($this->resource),
            'source_service' => $this->source_service,
            'target_service' => $this->target_service,
            'status' => $this->status->value,
            'status_version' => $this->status_version,
            'requires_approval' => $this->requires_approval,
            'payload' => resolve(ActionRequestBrowserPayload::class)->for($this->resource),
            'result' => $request->user()?->isAdmin() ? $this->result : resolve(ActionRequestBrowserPayload::class)->safeResult($this->resource),
            'approved_by' => $this->whenLoaded('approvedByUser', fn () => $this->approvedByUser?->name),
            'webhook_source' => $this->whenLoaded('webhookEvent', fn () => $this->webhookEvent?->serviceConnection?->name),
            'replacement_attempt' => $this->whenLoaded('mediaReplacementAttempt', fn (): ?array => $this->mediaReplacementAttempt === null ? null : [
                'id' => $this->mediaReplacementAttempt->id,
                'status' => $this->mediaReplacementAttempt->status->value,
                'failure_reason' => $this->mediaReplacementAttempt->failure_reason,
            ]),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
