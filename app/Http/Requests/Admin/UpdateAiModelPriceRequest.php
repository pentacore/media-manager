<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\RateLimitMetric;
use App\Enums\RateLimitPeriod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
            'rate_limits' => ['nullable', 'array'],
            'rate_limits.*.metric' => ['required', 'string', RateLimitMetric::validationRule()],
            'rate_limits.*.period' => ['required', 'string', RateLimitPeriod::validationRule()],
            'rate_limits.*.limit_value' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * The unique DB index on (price, metric, period) would reject
     * duplicates anyway — validating here turns a 500 into a field error.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $seen = [];

                foreach ((array) $this->input('rate_limits', []) as $index => $row) {
                    $key = ($row['metric'] ?? '').'|'.($row['period'] ?? '');

                    if (isset($seen[$key])) {
                        $validator->errors()->add(
                            sprintf('rate_limits.%s.metric', $index),
                            __('Duplicate metric/period combination.'),
                        );
                    }

                    $seen[$key] = true;
                }
            },
        ];
    }
}
