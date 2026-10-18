<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Ai\Decision\SecondOpinionVerifier;
use App\Models\ActionTypeConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;
use Pentacore\Typefinder\Attributes\TypefinderResource;

/**
 * @mixin ActionTypeConfig
 */
#[TypefinderResource(shape: [
    'id' => 'number',
    'type' => 'string',
    'label' => 'string',
    'description' => 'string | null',
    'requires_approval' => 'boolean',
    'is_enabled' => 'boolean',
    'second_opinion' => "'off' | 'hold' | 'reject'",
    'second_opinion_supported' => 'boolean',
])]
class ActionTypeConfigResource extends JsonResource
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
            'label' => $this->label,
            'description' => $this->description,
            'requires_approval' => $this->requires_approval,
            'is_enabled' => $this->is_enabled,
            'second_opinion' => $this->second_opinion->value,
            'second_opinion_supported' => SecondOpinionVerifier::supportsActionType($this->type),
        ];
    }
}
