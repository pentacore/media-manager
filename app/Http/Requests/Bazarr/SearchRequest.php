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
            'connection' => ['required', 'integer', 'min:1'],
            'media_type' => ['required', 'in:episode,movie'],
            'media_id' => ['required', 'integer', 'min:1'],
            'target_fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'path' => ['prohibited'],
            'url' => ['prohibited'],
            'subtitle' => ['prohibited'],
            'provider' => ['prohibited'],
            'provider_url' => ['prohibited'],
        ];
    }
}
