<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\AiModelPriceSelectionValidationRules;
use App\Concerns\RateLimitValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Applies the same settings to many model price rows. Every setting is
 * optional and an absent field leaves it unchanged on every row: a present
 * null `free_usage_pool_id` clears the pool, and a present `rate_limits`
 * list (even an empty one) replaces the rows' limits.
 */
class BulkUpdateAiModelPriceRequest extends FormRequest
{
    use AiModelPriceSelectionValidationRules;
    use RateLimitValidationRules {
        after as rateLimitAfter;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->aiModelPriceSelectionRules(),
            'automatic_updates_enabled' => ['nullable', 'boolean'],
            'free_usage_pool_id' => ['nullable', 'integer', 'exists:ai_free_usage_pools,id'],
            ...$this->rateLimitRules(),
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            ...$this->rateLimitAfter(),
            function (Validator $validator): void {
                $changesSomething = $this->input('automatic_updates_enabled') !== null
                    || $this->has('free_usage_pool_id')
                    || is_array($this->input('rate_limits'));

                if (! $changesSomething) {
                    $validator->errors()->add('ids', __('Choose at least one setting to change.'));
                }
            },
        ];
    }
}
