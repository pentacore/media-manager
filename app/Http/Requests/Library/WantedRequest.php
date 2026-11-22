<?php

declare(strict_types=1);

namespace App\Http\Requests\Library;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WantedRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /** @query */
            'tab' => ['nullable', Rule::in(['missing', 'cutoff'])],
            /** @query */
            'monitored' => ['nullable', 'boolean'],
            /** @query */
            'sonarr_page' => ['nullable', 'integer', 'min:1'],
            /** @query */
            'radarr_page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
