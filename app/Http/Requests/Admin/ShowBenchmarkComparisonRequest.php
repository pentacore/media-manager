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
use Pentacore\Typefinder\Attributes\TypefinderOverrides;

/**
 * The comparison URL: `runs` (comma-separated ids), an optional baseline,
 * manual pairs and score source. A bad URL lands on the runs list with the
 * reason as a toast.
 *
 * `baseline` is overridden for Typefinder: Rule::in() over the bound run ids is
 * empty when it calls rules(), and an empty in-list drops the integer type
 * (https://github.com/pentacore/Laravel-Typefinder/issues/230).
 */
#[TypefinderOverrides(['baseline' => 'number | null'])]
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
            /** @query */
            'baseline' => ['nullable', 'integer', Rule::in((array) $this->input('run_ids', []))],
            /** @query */
            'pairs' => ['nullable', 'string', 'max:500'],
            /** @query */
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
