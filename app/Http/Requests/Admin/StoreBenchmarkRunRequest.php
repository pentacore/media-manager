<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\AiModelSelectionValidationRules;
use App\Enums\AiReasoningLevel;
use App\Models\BenchmarkCase;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;

/**
 * `candidates` and `judge` are overridden because typefinder cannot render
 * their wildcard rules.
 */
#[TypefinderOverrides([
    'candidates' => 'Array<{ provider: string; model: string; reasoning: string | null }>',
    'judge' => '{ provider: string; model: string; reasoning: string | null }',
])]
class StoreBenchmarkRunRequest extends FormRequest
{
    use AiModelSelectionValidationRules;

    public const int MAX_CANDIDATES = 8;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'case_ids' => ['required', 'array', 'list', 'min:1'],
            'case_ids.*' => ['integer', 'distinct', Rule::exists(BenchmarkCase::class, 'id')],
            'candidates' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_CANDIDATES],
            'candidates.*' => ['required', 'array:provider,model,reasoning'],
            ...$this->requiredSelectionRules('candidates.*'),
            'judge' => ['required', 'array:provider,model,reasoning'],
            ...$this->requiredSelectionRules('judge'),
            'cost_cap_usd' => ['nullable', 'numeric', 'gt:0'],
            'confirm_estimate_usd' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ((array) $this->input('candidates', []) as $index => $candidate) {
                if (is_array($candidate)) {
                    $this->validatePricedSelection($validator, sprintf('candidates.%d', $index), $candidate['provider'] ?? null, $candidate['model'] ?? null);
                }
            }

            $judge = $this->input('judge');

            if (is_array($judge)) {
                $this->validatePricedSelection($validator, 'judge', $judge['provider'] ?? null, $judge['model'] ?? null);
            }
        }];
    }

    /**
     * A selection whose provider and model are both mandatory.
     *
     * @return array<string, array<int, mixed>>
     */
    private function requiredSelectionRules(string $prefix): array
    {
        $rules = $this->selectionRules($prefix);

        $rules[$prefix.'.provider'][0] = 'required';
        $rules[$prefix.'.model'][0] = 'required';
        $rules[$prefix.'.reasoning'] = ['nullable', 'string', AiReasoningLevel::validationRule()];

        return $rules;
    }
}
