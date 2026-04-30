<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAiModelPriceRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'provider' => [
                'required',
                'string',
                'max:50',
                Rule::unique('ai_model_prices')->where(fn ($query) => $query->where('model', $this->input('model'))),
            ],
            'model' => ['required', 'string', 'max:100'],
            'input_per_mtok' => ['required', 'numeric', 'min:0', 'max:9999.9999'],
            'output_per_mtok' => ['required', 'numeric', 'min:0', 'max:9999.9999'],
            'cache_read_per_mtok' => ['required', 'numeric', 'min:0', 'max:9999.9999'],
            'cache_write_per_mtok' => ['required', 'numeric', 'min:0', 'max:9999.9999'],
            'reasoning_per_mtok' => ['required', 'numeric', 'min:0', 'max:9999.9999'],
            'batch_input_per_mtok' => ['nullable', 'numeric', 'min:0', 'max:9999.9999'],
            'batch_output_per_mtok' => ['nullable', 'numeric', 'min:0', 'max:9999.9999'],
            'batch_cache_read_per_mtok' => ['nullable', 'numeric', 'min:0', 'max:9999.9999'],
            'batch_cache_write_per_mtok' => ['nullable', 'numeric', 'min:0', 'max:9999.9999'],
            'batch_reasoning_per_mtok' => ['nullable', 'numeric', 'min:0', 'max:9999.9999'],
            'free_input_tokens_per_month' => ['nullable', 'integer', 'min:0'],
            'free_output_tokens_per_month' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
