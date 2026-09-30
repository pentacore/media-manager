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
            'tab' => ['nullable', Rule::in(['missing', 'cutoff'])],
            'monitored' => ['nullable', 'boolean'],
            'sonarr_page' => ['nullable', 'integer', 'min:1'],
            'radarr_page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
