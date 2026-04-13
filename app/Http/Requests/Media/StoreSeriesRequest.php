<?php

declare(strict_types=1);

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

class StoreSeriesRequest extends FormRequest
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
            'tvdbId' => ['required', 'integer'],
            'qualityProfileId' => ['required', 'integer'],
            'rootFolderPath' => ['required', 'string', 'max:1000'],
            'monitored' => ['sometimes', 'boolean'],
            'seasonFolder' => ['sometimes', 'boolean'],
            'addOptions' => ['sometimes', 'array'],
            'addOptions.searchForMissingEpisodes' => ['sometimes', 'boolean'],
            'images' => ['sometimes', 'array'],
            'seasons' => ['sometimes', 'array'],
        ];
    }
}
