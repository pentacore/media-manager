<?php

declare(strict_types=1);

namespace App\Http\Resources\Bazarr;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;
use Pentacore\Typefinder\Attributes\TypefinderResource;

#[TypefinderResource(shape: [
    'media_type' => "'episode' | 'movie'",
    'media_id' => 'number',
    'series_id' => 'number | undefined',
    'target_fingerprint' => 'string',
    'scope' => "'anime' | 'tv' | 'movie' | undefined",
    'title' => 'string',
    'subtitle_tracks' => "Array<{ fingerprint: string; language: string; display_name: string; kind: 'embedded' | 'external'; forced: boolean; hearing_impaired: boolean }>",
    'required_languages' => 'string[]',
    'missing_languages' => 'string[]',
    'monitored' => 'boolean',
])]
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
            'target_fingerprint' => $resource['target_fingerprint'] ?? null,
            'scope' => $resource['scope'] ?? null,
            'title' => $resource['title'] ?? null,
            'subtitle_tracks' => $resource['subtitle_tracks'] ?? [],
            'required_languages' => $resource['required_languages'] ?? [],
            'missing_languages' => $resource['missing_languages'] ?? [],
            'monitored' => $resource['monitored'] ?? false,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
