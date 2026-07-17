<?php

declare(strict_types=1);

namespace App\Http\Resources\Bazarr;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

class SubtitleHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        $resource = is_array($this->resource) ? $this->resource : [];

        return array_filter([
            'media_type' => $resource['media_type'] ?? null,
            'media_id' => $resource['media_id'] ?? null,
            'title' => $resource['title'] ?? null,
            'language' => $resource['language'] ?? null,
            'provider' => $resource['provider'] ?? null,
            'action' => $resource['action'] ?? null,
            'score' => $resource['score'] ?? null,
            'occurred_at' => $resource['occurred_at'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
