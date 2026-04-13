<?php

declare(strict_types=1);

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

class StoreMovieRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:500'],
            'tmdbId' => ['required', 'integer'],
            'qualityProfileId' => ['required', 'integer'],
            'rootFolderPath' => ['required', 'string', 'max:1000'],
            'monitored' => ['sometimes', 'boolean'],
            'minimumAvailability' => ['sometimes', 'string'],
            'addOptions' => ['sometimes', 'array'],
            'addOptions.searchForMovie' => ['sometimes', 'boolean'],
            'images' => ['sometimes', 'array'],
            'year' => ['sometimes', 'integer'],
        ];
    }
}
