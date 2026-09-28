<?php

declare(strict_types=1);

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DiscoverMediaRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tmdbId' => ['required', 'integer', 'min:1'],
            'mediaType' => ['required', Rule::in(['movie', 'tv'])],
            'seasons' => ['nullable', 'array'],
            'seasons.*' => ['integer', 'min:1'],
            'userId' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
