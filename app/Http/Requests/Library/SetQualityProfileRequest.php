<?php

declare(strict_types=1);

namespace App\Http\Requests\Library;

use App\Concerns\MediaActionValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class SetQualityProfileRequest extends FormRequest
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
            'quality_profile_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
