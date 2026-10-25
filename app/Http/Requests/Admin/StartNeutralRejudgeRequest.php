<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\AiModelSelectionValidationRules;
use App\Concerns\BenchmarkComparisonValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;

/**
 * `judge` is overridden because typefinder cannot render its wildcard rules.
 */
#[TypefinderOverrides([
    'judge' => '{ provider: string; model: string; reasoning: string | null }',
])]
class StartNeutralRejudgeRequest extends FormRequest
{
    use AiModelSelectionValidationRules;
    use BenchmarkComparisonValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->comparisonRunRules(),
            'judge' => ['required', 'array:provider,model,reasoning'],
            ...$this->requiredSelectionRules('judge'),
            'cost_cap_usd' => ['required', 'numeric', 'gt:0', 'max:1000'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $judge = $this->input('judge');

            if (is_array($judge)) {
                $this->validatePricedSelection($validator, 'judge', $judge['provider'] ?? null, $judge['model'] ?? null);
            }

            $this->validateComparableRuns($validator);
        }];
    }
}
