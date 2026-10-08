<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ChatTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;
use Pentacore\Typefinder\Attributes\TypefinderResource;

/**
 * @mixin ChatTemplate
 */
#[TypefinderResource(shape: [
    'id' => 'number',
    'name' => 'string',
    'body' => 'string',
    'variables' => "Array<{ name: string; label: string; type: 'text' | 'number' | 'choice' | 'series' | 'movie'; default: string | null; options: string[] | null }>",
    'auto_send' => 'boolean',
    'pinned' => 'boolean',
    'preset' => '{ provider: string | null; model: string | null; reasoning: AiReasoningLevel | null } | null',
    'last_used_at' => 'string | null',
])]
class ChatTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'body' => $this->body,
            'variables' => $this->variables,
            'auto_send' => $this->auto_send,
            'pinned' => $this->pinned,
            'preset' => $this->model === null && $this->reasoning === null ? null : [
                'provider' => $this->model_provider,
                'model' => $this->model,
                'reasoning' => $this->reasoning?->value,
            ],
            'last_used_at' => $this->last_used_at?->toIso8601String(),
        ];
    }
}
