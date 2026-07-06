<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\FreeUsagePeriod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAiFreeUsagePoolRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('ai_free_usage_pools', 'name')->ignore($this->route('aiFreeUsagePool')),
            ],
            'period' => ['required', FreeUsagePeriod::validationRule()],
            'unified' => ['required', 'boolean'],
            'free_total_tokens' => [
                'nullable', 'integer', 'min:0',
                Rule::requiredIf(fn (): bool => $this->boolean('unified')),
            ],
            'free_input_tokens' => [
                'nullable', 'integer', 'min:0',
                Rule::requiredIf(fn (): bool => ! $this->boolean('unified') && $this->input('free_output_tokens') === null),
            ],
            'free_output_tokens' => ['nullable', 'integer', 'min:0'],
            'documentation_url' => ['nullable', 'url', 'max:2048'],
        ];
    }
}
