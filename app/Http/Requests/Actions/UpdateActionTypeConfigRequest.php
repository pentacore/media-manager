<?php

declare(strict_types=1);

namespace App\Http\Requests\Actions;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateActionTypeConfigRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'requires_approval' => ['required', 'boolean'],
            'is_enabled' => ['required', 'boolean'],
        ];
    }
}
