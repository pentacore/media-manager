<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\AiMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAiSettingsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', AiMode::validationRule()],
            'model' => ['required', 'string', 'max:100'],
        ];
    }
}
