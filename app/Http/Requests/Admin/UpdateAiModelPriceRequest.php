<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAiModelPriceRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
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
            'free_usage_pool_id' => ['nullable', 'integer', 'exists:ai_free_usage_pools,id'],
        ];
    }
}
