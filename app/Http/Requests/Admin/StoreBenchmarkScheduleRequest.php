<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\AiModelSelectionValidationRules;
use App\Enums\AiTask;
use App\Enums\BenchmarkScheduleFrequency;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;

#[TypefinderOverrides([
    'judge' => '{ provider: string; model: string; reasoning: string | null }',
])]
class StoreBenchmarkScheduleRequest extends FormRequest
{
    use AiModelSelectionValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $benchmarkable = array_map(
            static fn (AiTask $aiTask): string => $aiTask->value,
            array_values(array_filter(AiTask::cases(), static fn (AiTask $aiTask): bool => $aiTask->isBenchmarkable())),
        );

        return [
            'name' => ['required', 'string', 'max:160'],
            'task' => ['required', 'string', Rule::in($benchmarkable)],
            'case_tags' => ['present', 'array', 'list', 'max:20'],
            'case_tags.*' => ['string', 'max:60', 'distinct'],
            'judge' => ['required', 'array:provider,model,reasoning'],
            ...$this->requiredSelectionRules('judge'),
            'cost_cap_usd' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'frequency' => ['required', BenchmarkScheduleFrequency::validationRule()],
            'time_of_day' => ['required', 'date_format:H:i'],
            'weekday' => ['nullable', 'required_if:frequency,weekly', 'integer', 'between:1,7'],
            'day_of_month' => ['nullable', 'required_if:frequency,monthly', 'integer', 'between:1,28'],
            'pass_rate_threshold' => ['required', 'numeric', 'gt:0', 'max:100'],
            'score_threshold' => ['required', 'numeric', 'gt:0', 'max:100'],
            'enabled' => ['required', 'boolean'],
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
        }];
    }
}
