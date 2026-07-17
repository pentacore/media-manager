<?php

declare(strict_types=1);

namespace App\Http\Resources\Bazarr;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

class SubtitleItemResource extends JsonResource
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
            'series_id' => $resource['series_id'] ?? null,
            'scope' => $resource['scope'] ?? null,
            'title' => $resource['title'] ?? null,
            'subtitle_tracks' => $resource['subtitle_tracks'] ?? [],
            'required_languages' => $resource['required_languages'] ?? [],
            'missing_languages' => $resource['missing_languages'] ?? [],
            'monitored' => $resource['monitored'] ?? false,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
