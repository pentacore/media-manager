<?php

declare(strict_types=1);

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A Seerr request for a mapped seasonal anime entry.
 */
class RequestAnimeRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'tmdbId' => ['required', 'integer'],
            'mediaType' => ['required', 'in:tv,movie'],
            'tmdbSeason' => ['nullable', 'integer'],
            'startDate' => ['nullable', 'date'],
            'userId' => ['nullable', 'integer'],
        ];
    }
}
