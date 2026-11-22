<?php

declare(strict_types=1);

namespace App\Http\Requests\Bazarr;

use Illuminate\Foundation\Http\FormRequest;

final class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isMember() === true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /** @query */
            'connection' => ['required', 'integer', 'min:1'],
            /** @query */
            'media_type' => ['required', 'in:episode,movie'],
            /** @query */
            'media_id' => ['required', 'integer', 'min:1'],
            /** @query */
            'target_fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            /** @query */
            'path' => ['prohibited'],
            /** @query */
            'url' => ['prohibited'],
            /** @query */
            'subtitle' => ['prohibited'],
            /** @query */
            'provider' => ['prohibited'],
            /** @query */
            'provider_url' => ['prohibited'],
        ];
    }
}
