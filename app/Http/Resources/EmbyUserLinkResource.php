<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Override;
use App\Models\EmbyUserLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Pentacore\Typefinder\Attributes\TypefinderResource;

/**
 * @mixin EmbyUserLink
 */
#[TypefinderResource(shape: [
    'id' => 'number',
    'emby_user_id' => 'string',
    'emby_username' => 'string',
    'created_at' => 'string | null',
    'user' => '{ id: number; name: string; email: string } | null',
])]
class EmbyUserLinkResource extends JsonResource
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
            'emby_user_id' => $this->emby_user_id,
            'emby_username' => $this->emby_username,
            'created_at' => $this->created_at?->toISOString(),
            'user' => $this->whenLoaded('user', fn (): ?array => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ] : null),
        ];
    }
}
