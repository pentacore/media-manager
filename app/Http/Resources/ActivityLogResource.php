<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Override;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Pentacore\Typefinder\Attributes\TypefinderResource;

/**
 * @mixin ActivityLog
 */
#[TypefinderResource(shape: [
    'id' => 'number',
    'action' => 'string',
    'description' => 'string',
    'user_name' => 'string | null',
    'service_name' => 'string | null',
    'service_type' => 'string | null',
    'created_at' => 'string | null',
])]
class ActivityLogResource extends JsonResource
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
            'action' => $this->action,
            'description' => $this->description,
            'user_name' => $this->whenLoaded('user', fn () => $this->user?->name),
            'service_name' => $this->whenLoaded('serviceConnection', fn () => $this->serviceConnection?->name),
            'service_type' => $this->whenLoaded('serviceConnection', fn () => $this->serviceConnection?->type->value),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
