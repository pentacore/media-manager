<?php

declare(strict_types=1);

namespace App\Http\Requests\Whisparr;

use App\Concerns\WhisparrActionValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class SetWhisparrQualityProfileRequest extends FormRequest
{
    use WhisparrActionValidationRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->whisparrItemRules(),
            'quality_profile_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
