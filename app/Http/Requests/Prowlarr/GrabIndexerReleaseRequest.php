<?php

declare(strict_types=1);

namespace App\Http\Requests\Prowlarr;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GrabIndexerReleaseRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'release_key' => ['required', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/'],
            'indexer_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
