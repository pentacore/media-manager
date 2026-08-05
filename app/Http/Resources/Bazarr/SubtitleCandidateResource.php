<?php

declare(strict_types=1);

namespace App\Http\Resources\Bazarr;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;
use Pentacore\Typefinder\Attributes\TypefinderResource;

#[TypefinderResource(shape: [
    'fingerprint' => 'string',
    'provider' => 'string',
    'language' => 'string',
    'forced' => 'boolean',
    'hearing_impaired' => 'boolean',
    'score' => 'number | null',
    'release_info' => 'string[]',
    'original_format' => 'boolean',
    'uploader' => 'string | null',
])]
final class SubtitleCandidateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        $resource = is_array($this->resource) ? $this->resource : [];

        return [
            'fingerprint' => $resource['fingerprint'] ?? '',
            'provider' => $resource['provider'] ?? '',
            'language' => $resource['language'] ?? '',
            'forced' => ($resource['forced'] ?? false) === true,
            'hearing_impaired' => ($resource['hearing_impaired'] ?? false) === true,
            'score' => is_numeric($resource['score'] ?? null) ? (float) $resource['score'] : null,
            'release_info' => is_array($resource['release_info'] ?? null) ? $resource['release_info'] : [],
            'original_format' => ($resource['original_format'] ?? false) === true,
            'uploader' => is_string($resource['uploader'] ?? null) ? $resource['uploader'] : null,
        ];
    }
}
