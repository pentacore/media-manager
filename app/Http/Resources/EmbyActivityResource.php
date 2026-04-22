<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Override;
use App\Models\EmbyActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Pentacore\Typefinder\Attributes\TypefinderResource;

/**
 * @mixin EmbyActivity
 */
#[TypefinderResource(shape: [
    'id' => 'number',
    'media_type' => 'string | null',
    'media_title' => 'string | null',
    'series_title' => 'string | null',
    'action' => 'string | null',
    'play_position' => 'number | null',
    'duration_ticks' => 'number | null',
    'emby_username' => 'string | null',
    'created_at' => 'string | null',
])]
class EmbyActivityResource extends JsonResource
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
            'media_type' => $this->media_type,
            'media_title' => $this->media_title,
            'series_title' => $this->series_title,
            'action' => $this->action,
            'play_position' => $this->play_position,
            'duration_ticks' => $this->duration_ticks,
            'emby_username' => $this->whenLoaded('embyUserLink', fn () => $this->embyUserLink?->emby_username),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
