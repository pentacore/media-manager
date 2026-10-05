<?php

declare(strict_types=1);

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A user-confirmed AniList/MAL → TMDB match, persisted and then requested.
 */
class ConfirmAnimeMatchRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'anilistId' => ['nullable', 'integer'],
            'malId' => ['nullable', 'integer'],
            'tmdbId' => ['required', 'integer'],
            // The chosen candidate's own media type is authoritative — it may
            // differ from the anime's format, and it decides which TMDB
            // namespace we persist + request against.
            'mediaType' => ['required', 'in:tv,movie'],
            'tmdbSeason' => ['nullable', 'integer'],
            'startDate' => ['nullable', 'date'],
            'userId' => ['nullable', 'integer'],
        ];
    }
}
