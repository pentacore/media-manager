<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\RateLimitMetric;
use App\Enums\RateLimitPeriod;
use Illuminate\Validation\Validator;

trait RateLimitValidationRules
{
    /**
     * @return array<string, array<mixed>>
     */
    protected function rateLimitRules(): array
    {
        return [
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
