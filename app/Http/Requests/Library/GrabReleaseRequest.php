<?php

declare(strict_types=1);

namespace App\Http\Requests\Library;

use App\Concerns\MediaActionValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class GrabReleaseRequest extends FormRequest
{
    use MediaActionValidationRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->mediaActionTargetRules(),
            'item_id' => ['required', 'integer', 'min:1'],
            'release_key' => ['required', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/'],
            'indexer_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
