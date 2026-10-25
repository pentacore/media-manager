<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\BenchmarkComparisonValidationRules;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Inertia\Inertia;
use Override;

/**
 * The comparison URL: `runs` (comma-separated ids), an optional baseline,
 * manual pairs and score source. A bad URL lands on the runs list with the
 * reason as a toast.
 */
class ShowBenchmarkComparisonRequest extends FormRequest
{
    use BenchmarkComparisonValidationRules;

    #[Override]
    protected $redirectRoute = 'admin.benchmarks.runs.index';

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->comparisonRunRules(),
            'baseline' => ['nullable', 'integer', Rule::in((array) $this->input('run_ids', []))],
            'pairs' => ['nullable', 'string', 'max:500'],
            'scores' => ['nullable', 'string', 'max:300'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [$this->validateComparableRuns(...)];
    }

    #[Override]
    protected function prepareForValidation(): void
    {
        $this->merge(['run_ids' => $this->runIdsFrom($this->query('runs'))]);
    }

    #[Override]
    protected function failedValidation(ValidatorContract $validator): void
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => (string) $validator->errors()->first()]);

        parent::failedValidation($validator);
    }
}
